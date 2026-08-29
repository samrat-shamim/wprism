<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

use WPrism\AdapterCertification;
use WPrism\AdapterLibrary;
use WPrism\AdapterSources;
use WPrism\Policy;
use WPrism\WithdrawnAuthoritySiteAdapterCertificate;

/**
 * `wprism adapter discover | install | update` — the channel that tells an
 * operator an adapter they do not have EXISTS, and installs or replaces it
 * from a digest-pinned index.
 *
 * ## What was missing, in one sentence
 *
 * `docs/guides/adapter-authoring.md` said it plainly until this file existed:
 * site-repository discovery, plugin-bundled discovery and packaged
 * installation all shipped, and "what remains absent is a remote/registry
 * mechanism that tells you an adapter you do not already have EXISTS". Every
 * one of the engine's three adapter sources answers a question about bytes
 * that are ALREADY on the disk. An operator whose site needed an adapter
 * somebody else had written had no way to learn of it, and no way to install
 * it that was anything but `curl | cp` with a hand-checked digest.
 *
 * `AdapterCatalog` has modelled the destination since issue #3339 — "an
 * independently distributed adapter PACKAGE is not a fourth source: it
 * installs into the site source as `adapters/<name>.json` plus
 * `adapters/certifications/<name>.json`, which this command already surveys
 * with --repo" (AdapterCatalog.php:186-191). This file is the act that
 * produces those two files, and it produces exactly those two files, so
 * everything the catalog, the pin, the certificate and the claim already say
 * about an installed adapter keeps saying it, byte for byte.
 *
 * ## The index is a POINTER document, and it carries no authority
 *
 * `wprism-adapter-index/v1` is unsigned by construction and confers nothing. It
 * says "these bytes exist, at this URL, with this digest, certified by this
 * key". Every trust decision is re-derived from the FETCHED bytes by
 * `AdapterCertification::verifyFile()`, the same call the live policy path
 * makes, against the trust root the operator's own repository already holds.
 * So the worst a tampered, replayed, truncated or hostile index can do is
 * deny service: change a digest and resolution refuses, change a URL and the
 * digest refuses, change the fingerprint and the enrolled-authority check
 * refuses, delete an entry and the adapter is not offered. It can never cause
 * an unverified byte to land in a repository. That is the whole reason this
 * format has no signature: adding one would create a second trust root with
 * its own custody, revocation and enrollment story, in front of a decision
 * that is already made downstream by a root that has all three
 * (spec/repo-format.md § v3.19, docs/wire-surface.md R-30).
 *
 * ## Resolution NEVER falls through
 *
 * `sandbox/bin/fetch-artifact.sh` is the model and it is quoted rather than
 * paraphrased: "a miss never falls through to a bare WP-CLI catalog install"
 * (:44-46). Every arm here is the same shape. No entry for the requested
 * version is a refusal, not a nearest match. A digest that does not match the
 * fetched bytes is a refusal, not a re-fetch. An unreachable URL is a refusal,
 * not the next URL. An out-of-window package is a refusal, not a warning. A
 * certificate that does not verify is a refusal, not an uncertified install —
 * because installing it uncertified is precisely the silent downgrade that
 * makes a distribution channel worth attacking.
 *
 * ## Nothing is fetched at runtime, and installation is an operator act
 *
 * AGENTS.md rule 1: the drop-in fetches nothing. This file lives in `cli/`,
 * runs on the operator's own machine, is WordPress-free, and is reached only
 * when a human types `wprism adapter install`. `agent/` gained no reader for the
 * index, no network path, and not one byte: an installed package is
 * indistinguishable to the agent from an adapter an operator hand-placed,
 * which is the property that let this ship without touching the load path.
 *
 * ## One transport ships, and the boundary is stated rather than hidden
 *
 * `file://` is the only scheme with a transport here. `https://` entries are
 * DISCOVERABLE — reading a vendor's index is exactly the missing capability,
 * and an entry you cannot yet install still tells you the adapter exists —
 * and refuse at install with `[transport_unavailable]` naming the mirror step.
 * That is not a stub: a fetcher no suite can exercise offline is an
 * unevidenced supply-chain surface in the one command whose entire job is to
 * refuse unevidenced bytes, and `fetch-artifact.sh` keeps its own network half
 * in the harness for the same reason. The mirror step an operator runs instead
 * is `curl` into a directory, which is a step they can audit.
 */
final class AdapterDistribution {
    /** The sub-verbs this class owns; `AdapterCatalog` owns list/inspect/doctor. */
    public const VERBS = ['discover', 'install', 'update'];

    /**
     * The index wire (spec/repo-format.md § v3.19).
     *
     * `/v1` is the whole change channel, exactly as R-01 records for the
     * certification domain: the key sets below are closed in BOTH directions,
     * so a member cannot be added for anyone holding today's agent, and growth
     * is a new `format` value read beside this one.
     */
    public const INDEX_FORMAT = 'wprism-adapter-index/v1';

    /** The report these three verbs emit; a different document from the index. */
    public const REPORT_FORMAT = 'wprism-adapter-distribution/v1';

    /** Closed both ways. An index with a `signature` member is refused BY NAME — see the class docblock. */
    private const INDEX_ENVELOPE_KEYS = ['adapters', 'format'];

    /**
     * One entry: what exists, where it is, and what it must hash to.
     *
     * The adapter NAME is the map key rather than a member, for the reason
     * `AdapterCertification::certificatePath()` derives its own path rather
     * than reading a declared one: a name that can be stated twice can be
     * stated inconsistently, and an entry that named itself could be filed
     * under one adapter while claiming to be another.
     */
    private const INDEX_ENTRY_KEYS = [
        'adapter_sha256', 'agent_versions', 'authority_fingerprint',
        'certificate_sha256', 'certificate_url', 'url', 'version',
    ];

    /** min inclusive, max exclusive — `Policy::assert_min_max_range()` is the author of this grammar. */
    private const AGENT_WINDOW_KEYS = ['max', 'min'];

    /** The one scheme with a shipped transport. See the class docblock. */
    public const TRANSPORT_FILE = 'file';

    /**
     * A bounded read, for the reason `fetch-artifact.sh` bounds its download:
     * a resolver that will read whatever it is handed is a memory-exhaustion
     * surface reachable from a document nobody signed. 4 MiB is more than 20x
     * the largest shipped manifest
     * (`adapter-packages/woocommerce/package/manifest.json`, 207,315 bytes),
     * so it bounds an attack without bounding an adapter.
     */
    private const MAX_PACKAGE_BYTES = 4194304;

    /**
     * The two site trust-root paths, built from `AdapterSources`' own
     * constants rather than from `AdapterCertify`'s copy of the same string:
     * this class must not require that one to be loaded, and these are the
     * exact expressions `AdapterCertification` builds its own private
     * `SITE_AUTHORITIES_RELATIVE` from (:510).
     */
    private const SITE_AUTHORITIES_RELATIVE =
        AdapterSources::SITE_DIR . '/' . AdapterSources::SITE_AUTHORITIES_FILE;
    private const SITE_DELEGATIONS_RELATIVE =
        AdapterSources::SITE_DIR . '/' . AdapterSources::SITE_DELEGATIONS_FILE;

    /** Installed-state words the discover report uses. */
    private const STATE_NOT_INSTALLED = 'not_installed';
    private const STATE_INSTALLED = 'installed';
    private const STATE_DIFFERS = 'differs_from_index';
    private const STATE_UNKNOWN = 'unknown';

    /**
     * TWO exit codes, not three. `AdapterCatalog`'s 1 means "a finding was
     * surfaced and the report carries it" — a report these verbs do not
     * produce, because there is no partial install: a package resolves and
     * lands, or the whole attempt refuses. Minting a 1 that never fires would
     * put a status in an operator's script that nothing can produce.
     *
     * @param list<string> $args the arguments after `wprism adapter`
     * @return int 0 the verb did what it says, 2 usage/IO/refusal
     */
    public static function run(array $args): int {
        $verb = $args[0] ?? '';
        $rest = array_slice($args, 1);

        try {
            return match ($verb) {
                'discover' => self::discover($rest),
                'install' => self::install($rest, false),
                'update' => self::install($rest, true),
                default => self::fail("unknown subcommand '$verb'"),
            };
        } catch (\Throwable $t) {
            // Same contract as `AdapterCertify::run()`: an operator input error
            // and the engine's own verbatim message are both exit 2 in this
            // command family, and the engine's coordinates are surfaced
            // unchanged rather than paraphrased.
            return self::fail($t->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // discover
    // -----------------------------------------------------------------

    /**
     * `wprism adapter discover --index=<file> [--repo=<site-repo>] [--format=json]`
     *
     * Read-only, and it fetches NOTHING. Discovery is the act of reading the
     * index; every fact in the report is either in the index document or on
     * the operator's own disk. That is what makes it safe to point at a
     * document nobody signed, and it is why an `https://` entry lists here and
     * refuses at install rather than the other way round.
     *
     * @param list<string> $args
     */
    private static function discover(array $args): int {
        $flags = self::flags($args, ['index', 'repo', 'format'], []);
        if ($flags['positional'] !== []) {
            return self::fail("discover takes no positional argument, got '{$flags['positional'][0]}'");
        }
        $json = self::jsonRequested($flags);
        if (is_int($json)) {
            return $json;
        }
        $indexPath = self::indexPath((string) ($flags['index'] ?? ''));
        $repo = self::optionalRepo($flags['repo'] ?? null);
        if (is_int($repo)) {
            return $repo;
        }

        self::boot();
        $index = self::readIndex($indexPath);

        // Read once, before the loop: the trust root is one file, and reading
        // it per entry would make a broken root refuse N times with the same
        // sentence — and would make the report's answer depend on how many
        // adapters the index happens to publish.
        $enrolled = $repo === null ? null : self::enrolledFingerprints($repo);
        $rows = [];
        foreach ($index['adapters'] as $name => $entries) {
            foreach ($entries as $entry) {
                $rows[] = self::discoverRow($name, $entry, $repo, $enrolled);
            }
        }

        $report = [
            'format' => self::REPORT_FORMAT,
            'command' => 'discover',
            'agent_version' => WPRISM_AGENT_VERSION,
            'index' => $indexPath,
            'index_format' => $index['format'],
            'repo' => $repo,
            'entries' => $rows,
            'deferred' => self::deferred($repo),
        ];

        if ($json === true) {
            echo self::encode($report) . "\n";
            return 0;
        }
        self::renderDiscover($report);
        return 0;
    }

    /**
     * One index entry judged against this machine, with nothing fetched.
     *
     * Three independent facts, deliberately not collapsed into one verdict
     * word: whether this AGENT is inside the entry's window, whether a
     * transport exists for its URL, and what this REPOSITORY currently holds
     * under that name. An operator reading "unavailable" would not know which
     * of the three to fix.
     *
     * @param array<string,mixed> $entry
     * @param array<string,true>|null $enrolled the repository's own trust root, or null when no --repo was given
     * @return array<string,mixed>
     */
    private static function discoverRow(string $name, array $entry, ?string $repo, ?array $enrolled): array {
        $installed = self::installedDigest($repo, $name);
        $state = self::STATE_UNKNOWN;
        if ($repo !== null) {
            if ($installed === null) {
                $state = self::STATE_NOT_INSTALLED;
            } else {
                $state = hash_equals((string) $entry['adapter_sha256'], $installed)
                    ? self::STATE_INSTALLED
                    : self::STATE_DIFFERS;
            }
        }

        return [
            'name' => $name,
            'version' => $entry['version'],
            'adapter_sha256' => $entry['adapter_sha256'],
            'certificate_sha256' => $entry['certificate_sha256'],
            'authority_fingerprint' => $entry['authority_fingerprint'],
            'agent_versions' => $entry['agent_versions'],
            'url' => $entry['url'],
            'certificate_url' => $entry['certificate_url'],
            'in_agent_window' => self::insideWindow(WPRISM_AGENT_VERSION, $entry['agent_versions']),
            'transport' => self::transportOf((string) $entry['url']),
            'certificate_transport' => self::transportOf((string) $entry['certificate_url']),
            'authority_enrolled' => $enrolled === null
                ? null
                : ($enrolled[(string) $entry['authority_fingerprint']] ?? false),
            'state' => $state,
            'installed_sha256' => $installed,
        ];
    }

    // -----------------------------------------------------------------
    // install / update
    // -----------------------------------------------------------------

    /**
     * `wprism adapter install <site-repo> --index=<f> --name=<n> [--version=<v>]`
     * `wprism adapter update  <site-repo> --index=<f> --name=<n> --to=<v>`
     *
     * ONE resolution and verification path with two entry conditions, rather
     * than two commands that each fetch and verify. A second copy of the
     * refusal ladder is a second copy that can drift, and the ladder is the
     * product here — `AdapterCatalog::run()` takes the same view of its own
     * three read-only verbs.
     *
     * The two conditions are the whole difference, and each exists because the
     * silent version of it is a data-loss bug:
     *
     *   install — refuses when `adapters/<name>.json` already exists. An
     *   install that overwrote would replace an adapter an operator may have
     *   authored, from a document nobody signed.
     *
     *   update — refuses when it does NOT exist, and refuses when the bytes
     *   on disk hash to nothing this index published. The second is the one
     *   that matters: a locally-edited adapter is a decision somebody took,
     *   and overwriting it from an index is how that decision disappears
     *   without a message.
     *
     * @param list<string> $args
     */
    private static function install(array $args, bool $isUpdate): int {
        $verb = $isUpdate ? 'update' : 'install';
        $valued = $isUpdate
            ? ['index', 'name', 'to', 'format', 'adapter-library']
            : ['index', 'name', 'version', 'format', 'adapter-library'];
        $flags = self::flags($args, $valued, []);
        $json = self::jsonRequested($flags);
        if (is_int($json)) {
            return $json;
        }
        $repo = self::onlySiteRepo($flags['positional'], $verb);
        if (is_int($repo)) {
            return $repo;
        }
        $name = (string) ($flags['name'] ?? '');
        if ($name === '') {
            return self::fail("--name=<adapter-name> is required: $verb installs ONE named package");
        }
        AdapterSources::assert_name($name, "adapter $verb --name");
        $version = trim((string) ($flags[$isUpdate ? 'to' : 'version'] ?? ''));
        if ($isUpdate && $version === '') {
            // `update` never picks a target. Choosing "the newest" needs an
            // ORDER over vendor version strings, and this format deliberately
            // defines none (§ v3.19): `version` is an opaque identity, so a
            // resolver that ranked them would be guessing at a grammar the
            // publisher never agreed to. The operator names the version.
            return self::fail(
                '--to=<version> is required: update never chooses a version for you, because '
                . self::INDEX_FORMAT . ' defines no order over version strings — run `wprism adapter discover '
                . "--index=<f> --repo=$repo` to see what is offered"
            );
        }

        self::boot();
        $adapterLibrary = self::adapterLibrary($flags['adapter-library'] ?? null);
        $indexPath = self::indexPath((string) ($flags['index'] ?? ''));
        $index = self::readIndex($indexPath);

        $entries = $index['adapters'][$name] ?? null;
        if ($entries === null) {
            return self::fail(
                "[package_not_indexed] no adapter named '$name' is published by $indexPath — "
                . 'resolution refuses rather than searching another source'
            );
        }
        $entry = self::selectEntry($name, $entries, $version, $indexPath);
        if (is_int($entry)) {
            return $entry;
        }

        $target = $repo . '/' . AdapterSources::SITE_DIR . '/' . $name . '.json';
        $installed = self::installedDigest($repo, $name);
        if (!$isUpdate && $installed !== null) {
            return self::fail(
                "[already_installed] $target already exists — install never overwrites an installed adapter; "
                . "use `wprism adapter update $repo --index=$indexPath --name=$name --to=<version>`"
            );
        }
        if ($isUpdate) {
            if ($installed === null) {
                return self::fail(
                    "[not_installed] there is no $target to update — use `wprism adapter install $repo "
                    . "--index=$indexPath --name=$name`"
                );
            }
            $known = false;
            foreach ($entries as $candidate) {
                $known = $known || hash_equals((string) $candidate['adapter_sha256'], $installed);
            }
            if (!$known) {
                return self::fail(
                    "[unmanaged_installation] the installed $target hashes to $installed, which no entry for "
                    . "'$name' in $indexPath publishes — those bytes were authored or edited on this side, and "
                    . 'update refuses to replace a local decision from an unsigned index. Move them aside '
                    . 'deliberately, then install'
                );
            }
            // Converged means the PAIR is converged, not just the adapter.
            // Checking the manifest alone would report `unchanged` for a
            // repository holding the right adapter beside a certificate that
            // is not this entry's — a state `AdapterSources` reports as
            // `certificate_invalid`, and the one an operator ran update to
            // fix. Falling through republishes both.
            $installedCertificate = self::installedDigest(
                $repo,
                $name,
                AdapterSources::CERTIFICATION_DIR . '/'
            );
            if (hash_equals((string) $entry['adapter_sha256'], $installed)
                && $installedCertificate !== null
                && hash_equals((string) $entry['certificate_sha256'], $installedCertificate)) {
                // Idempotent by digest, and it writes NOTHING. Re-running a
                // converged step is what a configuration run does on every
                // pass; re-publishing identical bytes would move mtimes and
                // make a no-op look like a change to whatever watches the
                // repository.
                $report = self::resultReport(
                    'update',
                    $name,
                    $entry,
                    $repo,
                    $indexPath,
                    'unchanged',
                    null
                );
                if ($json === true) {
                    echo self::encode($report) . "\n";
                } else {
                    echo "$name is already at version {$entry['version']} ($installed) — nothing written\n";
                }
                return 0;
            }
        }

        // Everything above is about this repository's state. Everything below
        // fetches, verifies, and only then writes — in a staging root, so a
        // refusal at any rung leaves the repository exactly as it was found
        // (`AdapterCertify::certify()`'s stated posture, and it was a real
        // defect there before it was a rule).
        $verified = self::resolveVerified($name, $entry, $repo, $indexPath, $adapterLibrary);
        $published = self::publish($repo, $name, $verified);

        $report = self::resultReport(
            $verb,
            $name,
            $entry,
            $repo,
            $indexPath,
            $isUpdate ? 'updated' : 'installed',
            $verified['claim']
        );
        $report['paths'] = $published;
        ksort($report, SORT_STRING);

        if ($json === true) {
            echo self::encode($report) . "\n";
            return 0;
        }
        echo ($isUpdate ? 'updated' : 'installed') . " $name version {$entry['version']}\n";
        echo "  adapter:     {$published['adapter']}\n";
        echo "  certificate: {$published['certificate']}\n";
        echo '  digest:      ' . $entry['adapter_sha256'] . "\n";
        echo '  claim:       ' . ($verified['claim']['status'] ?? '?') . ' ('
            . ($verified['claim']['trust_tier'] ?? '?') . ")\n";
        echo '  authority:   ' . ($verified['claim']['certification']['principal'] ?? '(none)')
            . ' (' . ($verified['claim']['certification']['trust_root'] ?? '?') . " trust root)\n";
        echo "\nThe adapter is INSTALLED, not PINNED. `wprism adapter pin $repo --name=$name` is the separate\n"
            . "act that makes this site load it — installation and adoption are deliberately two decisions.\n";
        return 0;
    }

    /**
     * Choose exactly one entry, or refuse with what was on offer.
     *
     * With no version stated the choice must be UNAMBIGUOUS: exactly one entry
     * inside this agent's window. Two in-window entries is a refusal that
     * lists both, never a silent pick — see the `--to` refusal above for why
     * there is no order to pick by.
     *
     * @param list<array<string,mixed>> $entries
     * @return array<string,mixed>|int
     */
    private static function selectEntry(string $name, array $entries, string $version, string $indexPath) {
        if ($version !== '') {
            foreach ($entries as $entry) {
                if ((string) $entry['version'] === $version) {
                    return $entry;
                }
            }
            return self::fail(
                "[version_not_indexed] $indexPath publishes no version '$version' of '$name'; it publishes "
                . implode(', ', array_map(static fn(array $e): string => (string) $e['version'], $entries))
                . ' — an exact version is resolved or refused, never approximated'
            );
        }
        $inWindow = array_values(array_filter(
            $entries,
            static fn(array $e): bool => self::insideWindow(WPRISM_AGENT_VERSION, $e['agent_versions'])
        ));
        if ($inWindow === []) {
            $windows = array_map(
                static fn(array $e): string => (string) $e['version'] . ' [' . $e['agent_versions']['min']
                    . ', ' . $e['agent_versions']['max'] . ')',
                $entries
            );
            return self::fail(
                "[package_out_of_window] no entry for '$name' in $indexPath is offered for agent "
                . WPRISM_AGENT_VERSION . '; the index publishes ' . implode(', ', $windows)
                . ' — an out-of-window package is refused, not installed with a warning'
            );
        }
        if (count($inWindow) > 1) {
            return self::fail(
                "[ambiguous_version] $indexPath offers " . count($inWindow) . " versions of '$name' for agent "
                . WPRISM_AGENT_VERSION . ' ('
                . implode(', ', array_map(static fn(array $e): string => (string) $e['version'], $inWindow))
                . ') — name one with --version=<v>; this format defines no order over version strings, so '
                . 'choosing for you would be a guess'
            );
        }
        return $inWindow[0];
    }

    /**
     * Fetch, pin, and verify — in a staging root the repository cannot see.
     *
     * The ladder, and every rung refuses rather than continuing:
     *
     *   1. the agent is inside the entry's window;
     *   2. a transport exists for both URLs;
     *   3. the fetched adapter bytes hash to `adapter_sha256`;
     *   4. the fetched certificate bytes hash to `certificate_sha256`;
     *   5. the adapter's own declared `name` is the name it was filed under;
     *   6. the authority fingerprint the index states is ENROLLED in this
     *      repository's own `adapters/authorities.json`;
     *   7. `AdapterCertification::verifyFile()` — the same call the live policy
     *      path makes — accepts the pair against that root;
     *   8. the authority it actually resolved is the one the index named.
     *
     * Rung 6 is where trust-on-first-use is refused. An index cannot enroll a
     * key: enrollment is an operator act under a root the operator owns, and a
     * channel that could add its own signer would be a channel that signs for
     * itself. Rung 8 is the other half — an enrolled key is not automatically
     * the RIGHT key, and a certificate swapped for one under a different
     * enrolled authority must not pass because both happen to be trusted.
     *
     * Rungs 7 and 8 are also where a revoked or expired authority refuses, and
     * they get it for free rather than by a second check here:
     * `AdapterCertification::authority()` resolves the key through
     * `assertNotRevoked()` and the v2 record's mandatory window, so the typed
     * revocation channel and a lapsed `not_after` reach this command by the
     * same door they reach every other verifier (§ v3.8). A distribution
     * command with its OWN revocation opinion would be the drift that makes a
     * revocation drill prove nothing about installs.
     *
     * @param array<string,mixed> $entry
     * @return array{adapter:string,certificate:string,claim:array<string,mixed>}
     */
    private static function resolveVerified(
        string $name,
        array $entry,
        string $repo,
        string $indexPath,
        AdapterLibrary $adapterLibrary
    ): array {
        if (!self::insideWindow(WPRISM_AGENT_VERSION, $entry['agent_versions'])) {
            throw new \RuntimeException(
                "[package_out_of_window] '$name' version {$entry['version']} is offered for agent ["
                . $entry['agent_versions']['min'] . ', ' . $entry['agent_versions']['max'] . '); this agent is '
                . WPRISM_AGENT_VERSION . ' — the window is the publisher\'s statement about what they exercised, '
                . 'and it is refused rather than widened here'
            );
        }

        $adapterRaw = self::fetch((string) $entry['url'], "adapter package '$name' " . $entry['version']);
        $adapterDigest = hash('sha256', $adapterRaw);
        if (!hash_equals((string) $entry['adapter_sha256'], $adapterDigest)) {
            throw new \RuntimeException(
                "[package_digest_mismatch] {$entry['url']} returned bytes hashing to $adapterDigest; "
                . "$indexPath pins {$entry['adapter_sha256']} for '$name' {$entry['version']} — a digest miss "
                . 'refuses, it never falls through to the bytes that were served'
            );
        }
        $certificateRaw = self::fetch(
            (string) $entry['certificate_url'],
            "certificate for '$name' " . $entry['version']
        );
        $certificateDigest = hash('sha256', $certificateRaw);
        if (!hash_equals((string) $entry['certificate_sha256'], $certificateDigest)) {
            throw new \RuntimeException(
                "[certificate_digest_mismatch] {$entry['certificate_url']} returned bytes hashing to "
                . "$certificateDigest; $indexPath pins {$entry['certificate_sha256']} — refused"
            );
        }

        $manifest = self::canonicalObject($adapterRaw, "adapter package '$name'");
        AdapterSources::assert_declared_name($manifest, $name, AdapterSources::SITE, (string) $entry['url']);

        $fingerprint = (string) $entry['authority_fingerprint'];
        if ((self::enrolledFingerprints($repo)[$fingerprint] ?? false) !== true) {
            throw new \RuntimeException(
                "[authority_not_enrolled] '$name' {$entry['version']} is certified by the key whose "
                . "fingerprint is $fingerprint, and no record in $repo/" . self::SITE_AUTHORITIES_RELATIVE
                . ' carries that public key — an index cannot enroll its own signer, so the operator enrolls '
                . 'the key deliberately (or removes it deliberately) and the install then resolves'
            );
        }

        $staging = self::staging($repo, $name, $adapterRaw, $certificateRaw);
        try {
            $verified = AdapterCertification::verifyFile(
                $adapterLibrary,
                $staging['root'],
                $name,
                $manifest,
                $staging['certificate']
            );
        } catch (WithdrawnAuthoritySiteAdapterCertificate $withdrawn) {
            self::removeTree($staging['scratch']);
            throw new \RuntimeException(
                '[authority_withdrawn:' . $withdrawn->withdrawal() . "] '$name' {$entry['version']} will not "
                . 'install: ' . $withdrawn->getMessage()
            );
        } catch (\Throwable $t) {
            self::removeTree($staging['scratch']);
            throw new \RuntimeException(
                "[package_unverifiable] '$name' {$entry['version']} will not install: " . $t->getMessage()
            );
        }
        self::removeTree($staging['scratch']);

        $resolved = (string) ($verified['provenance']['proof']['authority']['fingerprint'] ?? '');
        if (!hash_equals($fingerprint, $resolved)) {
            throw new \RuntimeException(
                "[authority_fingerprint_mismatch] $indexPath says '$name' {$entry['version']} is certified by "
                . "$fingerprint; the certificate actually verifies under $resolved — an enrolled key is not "
                . 'automatically the right key, and the index\'s statement is a pin, not a hint'
            );
        }

        return [
            'adapter' => $adapterRaw,
            'certificate' => $certificateRaw,
            'claim' => $verified['claim'],
        ];
    }

    /**
     * A throwaway site root holding exactly the pair under test plus this
     * repository's own trust root.
     *
     * `verifyFile()` reads `adapters/<name>.json`, the derived certificate
     * path, `adapters/authorities.json` and (when a key resolves nowhere else)
     * `adapters/delegations.json`, all relative to a repository root. Handing
     * it the REAL repository would mean writing unverified bytes into the
     * repository in order to find out whether they verify, which is the shape
     * of every install-then-roll-back bug. The trust root is copied rather than
     * symlinked because `ownedFile()` refuses a symlink anywhere on the path,
     * and it must: a root reached through a link is a root somebody else can
     * repoint.
     *
     * @return array{root:string,certificate:string,scratch:string}
     */
    private static function staging(string $repo, string $name, string $adapterRaw, string $certificateRaw): array {
        $scratch = self::scratchDir();
        $root = $scratch . '/repo';
        $certificates = $root . '/' . AdapterSources::SITE_DIR . '/' . AdapterSources::CERTIFICATION_DIR;
        if (!mkdir($certificates, 0700, true) && !is_dir($certificates)) {
            throw new \RuntimeException("wprism: cannot create the staging root at $certificates");
        }
        $adapterPath = $root . '/' . AdapterSources::SITE_DIR . '/' . $name . '.json';
        $certificatePath = $certificates . '/' . $name . '.json';
        self::writeExact($adapterPath, $adapterRaw);
        self::writeExact($certificatePath, $certificateRaw);
        foreach ([self::SITE_AUTHORITIES_RELATIVE, self::SITE_DELEGATIONS_RELATIVE] as $trust) {
            $from = $repo . '/' . $trust;
            if (is_file($from) && !is_link($from)) {
                self::writeExact($root . '/' . $trust, (string) file_get_contents($from));
            }
        }

        $resolved = realpath($certificatePath);
        if ($resolved === false) {
            throw new \RuntimeException("wprism: the staged certificate vanished at $certificatePath");
        }

        return ['root' => $root, 'certificate' => $resolved, 'scratch' => $scratch];
    }

    /**
     * Publish the verified pair, ADAPTER first.
     *
     * The order is the whole design. Between the two renames the repository
     * holds the new adapter beside the old certificate (or none), and that
     * state is LOUD: the certificate digest no longer matches, so
     * `AdapterSources` reports `certificate_invalid` for that one adapter, or
     * the adapter is simply `uncertified`. The reverse order produces an
     * ORPHAN certificate — a certificate with no adapter — which
     * `AdapterCertification::verifyDirectory()` refuses fatally, for the WHOLE
     * source ("only direct <name>.json certificate files are allowed"). A
     * crash between two writes should degrade one adapter's claim, never take
     * out every adapter in the repository.
     *
     * @param array{adapter:string,certificate:string,claim:array<string,mixed>} $verified
     * @return array{adapter:string,certificate:string}
     */
    private static function publish(string $repo, string $name, array $verified): array {
        $adapterPath = $repo . '/' . AdapterSources::SITE_DIR . '/' . $name . '.json';
        $certificatePath = $repo . '/' . AdapterSources::SITE_DIR . '/'
            . AdapterSources::CERTIFICATION_DIR . '/' . $name . '.json';
        foreach ([$adapterPath, $certificatePath] as $path) {
            if (is_link($path)) {
                throw new \RuntimeException(
                    "wprism: $path is a symbolic link; installation writes regular files and never follows one"
                );
            }
        }
        $directory = dirname($certificatePath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException("wprism: cannot create $directory");
        }
        self::atomicWrite($adapterPath, $verified['adapter']);
        self::atomicWrite($certificatePath, $verified['certificate']);

        return ['adapter' => $adapterPath, 'certificate' => $certificatePath];
    }

    // -----------------------------------------------------------------
    // the index document
    // -----------------------------------------------------------------

    /**
     * Read and validate one `wprism-adapter-index/v1`, whole, before anything is
     * resolved from it.
     *
     * Whole-document-first, the posture `AdapterCertification::authorityKeys()`
     * takes for a trust root and for the same reason: a malformed entry three
     * adapters down is a malformed DOCUMENT, and validating lazily would let a
     * resolution succeed out of a file the next resolution refuses.
     *
     * @return array{format:string,adapters:array<string,list<array<string,mixed>>>}
     */
    private static function readIndex(string $path): array {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("[index_unreadable] cannot read the adapter index at $path");
        }
        if (strlen($raw) > self::MAX_PACKAGE_BYTES) {
            throw new \RuntimeException(
                '[index_unreadable] the adapter index at ' . $path . ' exceeds ' . self::MAX_PACKAGE_BYTES
                . ' bytes; resolution reads a bounded document'
            );
        }
        $document = self::canonicalObject($raw, 'adapter index ' . $path);
        self::assertExactKeys($document, self::INDEX_ENVELOPE_KEYS, "adapter index $path");
        if (($document['format'] ?? null) !== self::INDEX_FORMAT) {
            throw new \RuntimeException(
                "[index_malformed] $path declares format " . var_export($document['format'] ?? null, true)
                . '; this agent reads ' . self::INDEX_FORMAT . ' — an index generation it does not implement '
                . 'is refused BY VERSION rather than read as a document it understands'
            );
        }
        // An index that publishes nothing is refused BY NAME rather than
        // reported as an empty list, and the reason is PHP rather than policy:
        // `json_decode($raw, true)` renders both `{}` and `[]` as the same
        // empty array, so "accept an empty map" is indistinguishable from
        // "accept an empty ARRAY where an object is required" — the exact
        // {} versus [] ambiguity every canonical reader in this tree refuses.
        // A mirror with nothing to offer publishes no index.
        if ($document['adapters'] === []) {
            throw new \RuntimeException(
                "[index_malformed] $path publishes no adapters; an index with nothing in it is refused rather "
                . 'than read as an empty offer, because a decoded empty object and a decoded empty array are '
                . 'the same value in this language'
            );
        }
        if (!is_array($document['adapters']) || array_is_list($document['adapters'])) {
            throw new \RuntimeException("[index_malformed] $path .adapters must be an object keyed by adapter name");
        }

        $adapters = [];
        foreach ($document['adapters'] as $name => $entries) {
            if (!is_string($name)) {
                // The same refusal `authorityKeys()` writes for its own key
                // map, and the same reason: PHP coerces a numeric JSON
                // object-map key to an integer, so a numeric-only adapter name
                // is not a safe map key in this language.
                throw new \RuntimeException(
                    "[index_malformed] $path .adapters contains the non-string key " . var_export($name, true)
                );
            }
            AdapterSources::assert_name($name, "adapter index $path .adapters key");
            if (!is_array($entries) || !array_is_list($entries) || $entries === []) {
                throw new \RuntimeException(
                    "[index_malformed] $path .adapters['$name'] must be a non-empty list of entries"
                );
            }
            $versions = [];
            foreach ($entries as $position => $entry) {
                if (!is_array($entry) || array_is_list($entry)) {
                    throw new \RuntimeException(
                        "[index_malformed] $path .adapters['$name'][$position] must be an object"
                    );
                }
                self::assertExactKeys($entry, self::INDEX_ENTRY_KEYS, "$path .adapters['$name'][$position]");
                self::assertEntry($entry, "$path .adapters['$name'][$position]");
                $version = (string) $entry['version'];
                if (isset($versions[$version])) {
                    // Two entries for one (name, version) make `--version=<v>`
                    // ambiguous, and an ambiguity a resolver has to break is an
                    // ambiguity it breaks by guessing.
                    throw new \RuntimeException(
                        "[index_malformed] $path publishes version '$version' of '$name' twice"
                    );
                }
                $versions[$version] = true;
            }
            $adapters[$name] = array_values($entries);
        }
        ksort($adapters, SORT_STRING);

        return ['format' => self::INDEX_FORMAT, 'adapters' => $adapters];
    }

    /**
     * One entry's members, judged individually.
     *
     * Every digest is a full sha256 in lowercase hex — a prefix would make two
     * different packages resolvable under one pin — and every URL is an
     * ABSOLUTE URL with a scheme. Relative references were considered and
     * refused: resolving one against the index's own location is a second path
     * grammar with its own traversal question, in the one document this
     * command is designed to distrust.
     *
     * @param array<string,mixed> $entry
     */
    private static function assertEntry(array $entry, string $label): void {
        foreach (['adapter_sha256', 'authority_fingerprint', 'certificate_sha256'] as $member) {
            $value = $entry[$member] ?? null;
            if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
                throw new \RuntimeException(
                    "[index_malformed] $label.$member must be a lowercase 64-hex sha256, got "
                    . var_export($value, true)
                );
            }
        }
        $version = $entry['version'] ?? null;
        if (!is_string($version) || preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]*$/D', $version) !== 1) {
            // The exact slug `fetch_artifact` accepts for an artifact version
            // (sandbox/bin/fetch-artifact.sh:57-58), because both are the same
            // thing: an opaque publisher-chosen label that must be safe in a
            // filename, a shell word and a JSON key, and that neither tool
            // parses for meaning.
            throw new \RuntimeException(
                "[index_malformed] $label.version must be an opaque version label "
                . '([0-9A-Za-z][0-9A-Za-z._-]*), got ' . var_export($version, true)
            );
        }
        foreach (['certificate_url', 'url'] as $member) {
            self::assertUrl($entry[$member] ?? null, "$label.$member");
        }
        $window = $entry['agent_versions'] ?? null;
        if (!is_array($window) || array_is_list($window)) {
            throw new \RuntimeException("[index_malformed] $label.agent_versions must be a {min, max} object");
        }
        self::assertExactKeys($window, self::AGENT_WINDOW_KEYS, "$label.agent_versions");
        // The engine's own window grammar, not a second one: min non-empty,
        // max non-empty, min strictly less than max, "wildcards/empty/unbounded
        // are not certifiable" (Policy::assert_min_max_range()).
        Policy::assert_min_max_range($window, "$label.agent_versions");
    }

    /**
     * An absolute URL whose scheme this command recognises.
     *
     * Recognised is not the same as transportable: an `https://` URL is a
     * well-formed pointer that `discover` prints and `install` refuses. What
     * is refused HERE is a URL that is not a URL — no scheme, whitespace, a
     * quote, a control character — because a malformed pointer in an index is
     * a malformed index, and finding out at fetch time would mean a document
     * that lists fine and refuses at the one moment an operator is committed.
     */
    private static function assertUrl(mixed $url, string $label): void {
        if (!is_string($url) || $url === '') {
            throw new \RuntimeException("[index_malformed] $label must be a non-empty absolute URL");
        }
        if (preg_match('/[\s\'"\\\\\x00-\x1f\x7f]/D', $url) === 1) {
            throw new \RuntimeException(
                "[index_malformed] $label contains whitespace, a quote, a backslash or a control character"
            );
        }
        if (preg_match('#^(file|https)://#D', $url) !== 1) {
            throw new \RuntimeException(
                "[index_malformed] $label must begin file:// or https://, got " . var_export($url, true)
                . ' — a scheme this command does not name is refused in the document rather than at fetch time'
            );
        }
        if (str_starts_with($url, 'file://')) {
            // file:///abs/path only. A file://host/path form names a network
            // location this command has no transport for, and file://relative
            // is not an absolute URL at all.
            $path = substr($url, strlen('file://'));
            if (!str_starts_with($path, '/') || str_contains($path, '/../') || str_ends_with($path, '/..')) {
                throw new \RuntimeException(
                    "[index_malformed] $label must be file:///<absolute-path> with no '..' segment, got "
                    . var_export($url, true)
                );
            }
        }
    }

    // -----------------------------------------------------------------
    // the transport seam
    // -----------------------------------------------------------------

    /** The scheme's transport name, or null when this build ships none for it. */
    private static function transportOf(string $url): ?string {
        return str_starts_with($url, 'file://') ? self::TRANSPORT_FILE : null;
    }

    /**
     * The one shipped transport: a bounded read of one regular local file.
     *
     * A directory, a symbolic link, a device, an unreadable file and an
     * oversize file each refuse by name. The symlink refusal is not
     * theoretical here — an index that pointed at a link inside a mirror
     * directory would read whatever the link's owner repointed it at, and the
     * digest pin would then be a pin on somebody else's choice of moment.
     */
    private static function fetch(string $url, string $label): string {
        $transport = self::transportOf($url);
        if ($transport === null) {
            throw new \RuntimeException(
                "[transport_unavailable] $label is published at $url, and this build ships exactly one "
                . 'transport (' . self::TRANSPORT_FILE . '://). Mirror the package and its certificate into a '
                . 'directory you control, publish an index naming them by file:// URL, and install from that — '
                . 'a network fetcher no offline suite can exercise is an unevidenced supply-chain surface in '
                . 'the one command whose job is to refuse unevidenced bytes'
            );
        }
        $path = substr($url, strlen('file://'));
        if (is_link($path)) {
            throw new \RuntimeException(
                "[package_unreachable] $label at $url is a symbolic link; the transport reads regular files "
                . 'only, because a link is a pointer its owner can repoint after the digest was published'
            );
        }
        if (!is_file($path)) {
            throw new \RuntimeException(
                "[package_unreachable] $label is not present at $url — a miss refuses; it never falls through "
                . 'to another source'
            );
        }
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_PACKAGE_BYTES) {
            throw new \RuntimeException(
                "[package_unreachable] $label at $url is unreadable or exceeds " . self::MAX_PACKAGE_BYTES
                . ' bytes'
            );
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("[package_unreachable] $label at $url could not be read");
        }
        return $raw;
    }

    /** Resolve only the installed library or one explicitly selected authoring/archive input. */
    private static function adapterLibrary(mixed $path): AdapterLibrary {
        if ($path === null) {
            return Policy::adapter_library_context();
        }
        $root = realpath((string) $path);
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException("adapter library '$path' is not a directory");
        }
        if (is_dir($root . '/adapter-packages') || is_dir($root . '/platform/adapter-library')) {
            return AdapterLibrary::fromSourceTree($root);
        }
        if (is_dir($root . '/adapters') && is_dir($root . '/platform')) {
            return AdapterLibrary::fromEmbeddedDirectory($root);
        }
        return AdapterLibrary::fromLegacyFlatDirectory($root);
    }

    // -----------------------------------------------------------------
    // this repository's own state
    // -----------------------------------------------------------------

    /**
     * The digest of the installed `adapters/<prefix><name>.json`, or null when
     * there is none.
     *
     * `$prefix` selects the certificate half (`certifications/`), which is the
     * only other file a package owns. A symbolic link answers null rather than
     * being followed, for the reason `AdapterCertification::ownedFile()`
     * refuses one: a file reached through a link is a file somebody else can
     * repoint, and reading it here would make "what is installed" a question
     * about the link's target.
     */
    private static function installedDigest(?string $repo, string $name, string $prefix = ''): ?string {
        if ($repo === null) {
            return null;
        }
        $path = $repo . '/' . AdapterSources::SITE_DIR . '/' . $prefix . $name . '.json';
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        return $raw === false ? null : hash('sha256', $raw);
    }

    /**
     * Every public key this repository's own trust root carries, by fingerprint.
     *
     * Read through `AdapterCertification::assert_site_authorities()` first, so
     * a broken root refuses the whole command rather than reporting every
     * adapter as "not enrolled" — the distinction the certification scan makes
     * for the identical file, for the identical reason: an operator who wrote
     * an authorities file believes their adapters are certifiable.
     *
     * Deliberately NOT memoised. A cache would be read twice per run at most,
     * and it would make this command's answer depend on when in the process
     * the root was last written — which is exactly the property a suite that
     * enrolls, revokes and re-enrolls a key must not have.
     *
     * @return array<string,true>
     */
    private static function enrolledFingerprints(string $repo): array {
        AdapterCertification::assert_site_authorities($repo);
        $file = $repo . '/' . self::SITE_AUTHORITIES_RELATIVE;
        $out = [];
        if (is_file($file) && !is_link($file)) {
            $document = self::canonicalObject(
                (string) file_get_contents($file),
                self::SITE_AUTHORITIES_RELATIVE
            );
            foreach ((array) ($document['keys'] ?? []) as $record) {
                $encoded = is_array($record) ? ($record['public_key'] ?? null) : null;
                $public = is_string($encoded) ? base64_decode($encoded, true) : false;
                if ($public !== false && $public !== '') {
                    $out[hash('sha256', $public)] = true;
                }
            }
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // reporting
    // -----------------------------------------------------------------

    /**
     * What these verbs do NOT answer, printed on every run.
     *
     * `AdapterCatalog`'s docblock states the rule and this file holds to it: a
     * tool that listed its limits only when something went wrong would let
     * silence read as "everything about this package is verified". Two of
     * these rows are the exact boundary this package chose, so they are not
     * apology text — they are where the reader goes next.
     *
     * @return list<array{status:string,surface:string,check:string,why:string}>
     */
    private static function deferred(?string $repo): array {
        $rows = [
            [
                'surface' => 'the index document itself',
                'check' => '(none — by design)',
                'why' => self::INDEX_FORMAT . ' carries no signature and confers no trust. Every entry is a '
                    . 'POINTER: the digests pin the bytes, and the bytes are verified against this '
                    . 'repository\'s own trust root by AdapterCertification::verifyFile(). A tampered index '
                    . 'can deny service and can never cause an unverified byte to be installed '
                    . '(spec/repo-format.md § v3.19, docs/wire-surface.md R-30)',
            ],
            [
                'surface' => 'https:// entries',
                'check' => 'the operator\'s own mirror step',
                'why' => 'this build ships exactly one transport, ' . self::TRANSPORT_FILE
                    . '://. An https:// entry is DISCOVERABLE — knowing the adapter exists is the point — and '
                    . 'refuses at install with [transport_unavailable]. Mirror the two files and publish an '
                    . 'index naming them by file:// URL',
            ],
            [
                'surface' => 'whether an installed adapter is LOADED',
                'check' => 'wprism adapter pin / wprism adapter list --repo=<site-repo>',
                'why' => 'installation writes ' . AdapterSources::SITE_DIR . '/<name>.json and '
                    . AdapterSources::SITE_DIR . '/' . AdapterSources::CERTIFICATION_DIR
                    . '/<name>.json and stops there. A site loads what its site.wprism.json pins, which is a '
                    . 'separate operator decision — the same split `wprism adapter certify --pin` makes',
            ],
            [
                'surface' => 'whether the adapter WORKS on your target',
                'check' => 'wprism capabilities <env> / wprism plan <env>',
                'why' => 'a certificate says an organization approves these exact bytes and the engine\'s '
                    . 'validators accept the grammar. Whether the declared plugin is installed, active and '
                    . 'inside its window on one running WordPress is a fact about that environment, and this '
                    . 'command reaches no environment at all',
            ],
        ];
        if ($repo === null) {
            array_unshift($rows, [
                'surface' => 'installed state',
                'check' => 'wprism adapter discover --index=<f> --repo=<site-repo>',
                'why' => 'no --repo was given, so every row\'s `state` is ' . self::STATE_UNKNOWN
                    . ' and `authority_enrolled` is null: which of these packages you already have, and '
                    . 'whether their signers are enrolled, are facts about one repository',
            ]);
        }
        foreach ($rows as $i => $row) {
            $rows[$i] = ['status' => 'deferred'] + $row;
        }
        return $rows;
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed>|null $claim
     * @return array<string,mixed>
     */
    private static function resultReport(
        string $command,
        string $name,
        array $entry,
        string $repo,
        string $indexPath,
        string $outcome,
        ?array $claim
    ): array {
        $report = [
            'format' => self::REPORT_FORMAT,
            'command' => $command,
            'agent_version' => WPRISM_AGENT_VERSION,
            'index' => $indexPath,
            'repo' => $repo,
            'name' => $name,
            'version' => $entry['version'],
            'adapter_sha256' => $entry['adapter_sha256'],
            'certificate_sha256' => $entry['certificate_sha256'],
            'authority_fingerprint' => $entry['authority_fingerprint'],
            'outcome' => $outcome,
            // The claim's own four-fact certification block, verbatim — it is
            // what `wprism assess` reads to print `Site-certified` with a
            // principal, and a second spelling here would be a second answer
            // to "who vouched". `claim_status` and `trust_tier` sit beside it
            // rather than inside it because that is where the claim itself
            // carries them (AdapterCertification.php:5010-5030).
            'certification' => $claim === null ? null : ($claim['certification'] ?? null),
            'claim_status' => $claim === null ? null : ($claim['status'] ?? null),
            'trust_tier' => $claim === null ? null : ($claim['trust_tier'] ?? null),
            'deferred' => self::deferred($repo),
        ];
        ksort($report, SORT_STRING);
        return $report;
    }

    /** @param array<string,mixed> $report */
    private static function renderDiscover(array $report): void {
        echo "index:         {$report['index']}\n";
        echo "agent version: {$report['agent_version']}\n";
        echo 'site repo:     ' . ($report['repo'] ?? '(none — pass --repo to see what you already have)') . "\n";
        // No empty-table branch: `readIndex()` refuses an index that publishes
        // nothing, so a report with no rows is unreachable and a branch for it
        // would be prose about a state the reader cannot be in.
        echo "\n";
        printf("%-24s %-12s %-18s %-10s %s\n", 'ADAPTER', 'VERSION', 'STATE', 'WINDOW', 'TRANSPORT');
        foreach ($report['entries'] as $row) {
            printf(
                "%-24s %-12s %-18s %-10s %s\n",
                $row['name'],
                $row['version'],
                $row['state'],
                $row['in_agent_window'] ? 'in' : 'OUT',
                $row['transport'] ?? 'unavailable'
            );
        }
        echo "\nDEFERRED — what this report does not answer:\n";
        foreach ($report['deferred'] as $row) {
            echo "  - {$row['surface']}: {$row['why']}\n";
            echo "    ({$row['check']})\n";
        }
    }

    // -----------------------------------------------------------------
    // shared plumbing
    // -----------------------------------------------------------------

    /** min inclusive, max exclusive — `AdapterRegistry::inside_range()` is the shipped arithmetic. */
    private static function insideWindow(string $version, mixed $window): bool {
        return is_array($window)
            && is_string($window['min'] ?? null) && is_string($window['max'] ?? null)
            && version_compare($version, $window['min'], '>=')
            && version_compare($version, $window['max'], '<');
    }

    /**
     * @param list<string> $args
     * @param list<string> $valued
     * @param list<string> $boolean
     * @return array<string,mixed>
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
                // Refused rather than last-wins, `AdapterCatalog`'s posture: a
                // second --index silently replacing the first would install
                // from a document the operator did not name.
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
            $value = trim(substr($arg, strlen($flag) + 3));
            if ($value === '') {
                throw new \RuntimeException("--$flag needs a non-empty value");
            }
            $out[$flag] = $value;
        }
        return $out;
    }

    /**
     * `--format=json` or nothing. Any other value is a usage error rather than
     * a silent fall back to the human renderer, because a script that asked
     * for JSON and got a table parses garbage.
     *
     * @param array<string,mixed> $flags
     * @return bool|int
     */
    private static function jsonRequested(array $flags) {
        $format = $flags['format'] ?? null;
        if ($format === null) {
            return false;
        }
        if ($format !== 'json') {
            return self::fail("--format takes 'json' (the default is the human report), got '$format'");
        }
        return true;
    }

    /** @param list<string> $positional @return string|int */
    private static function onlySiteRepo(array $positional, string $verb) {
        if ($positional === []) {
            return self::fail("$verb needs the site repository: wprism adapter $verb <site-repo> --index=<f> --name=<n>");
        }
        if (count($positional) > 1) {
            return self::fail("$verb takes exactly one site repository, got " . count($positional));
        }
        return self::siteRepo($positional[0]);
    }

    /** @return string|int|null */
    private static function optionalRepo(mixed $repo) {
        if ($repo === null) {
            return null;
        }
        return self::siteRepo((string) $repo);
    }

    /** @return string|int */
    private static function siteRepo(string $candidate) {
        $resolved = is_dir($candidate) ? realpath($candidate) : false;
        if ($resolved === false) {
            return self::fail("'$candidate' is not a directory");
        }
        if (!is_file($resolved . '/site.wprism.json')) {
            return self::fail(
                "'$resolved' has no site.wprism.json — this takes the wprism SITE REPO (the directory holding "
                . 'site.wprism.json), whose ' . AdapterSources::SITE_DIR . '/ source a package installs into'
            );
        }
        return rtrim($resolved, '/');
    }

    /** The index is a local path or a file:// URL naming one; both resolve to a real file here. */
    private static function indexPath(string $index): string {
        if ($index === '') {
            throw new \RuntimeException(
                '--index=<file> is required: it names the ' . self::INDEX_FORMAT . ' document to resolve from'
            );
        }
        if (str_starts_with($index, 'file://')) {
            self::assertUrl($index, '--index');
            $index = substr($index, strlen('file://'));
        }
        if (is_link($index)) {
            throw new \RuntimeException("[index_unreadable] --index '$index' is a symbolic link");
        }
        $resolved = is_file($index) ? realpath($index) : false;
        if ($resolved === false) {
            throw new \RuntimeException("[index_unreadable] --index '$index' is not a readable regular file");
        }
        return $resolved;
    }

    /**
     * Decode one canonical JSON OBJECT, with the parser's own words on failure.
     *
     * @return array<string,mixed>
     */
    private static function canonicalObject(string $raw, string $label): array {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("wprism: $label is not valid JSON: " . $e->getMessage());
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException("wprism: $label must be a JSON object");
        }
        return $decoded;
    }

    /**
     * Closed in BOTH directions, and it is the same rule
     * `AdapterCertification::assertExactKeys()` applies to every signed
     * grammar: a member no checker reads is indistinguishable from a
     * deliberate one, and an absent member is how a tamperer downgrades a
     * document by DELETING bytes.
     *
     * @param array<string,mixed> $value
     * @param list<string> $expected
     */
    private static function assertExactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        $wanted = $expected;
        sort($wanted, SORT_STRING);
        if ($actual !== $wanted) {
            throw new \RuntimeException(
                "[index_malformed] $label must carry exactly {" . implode(', ', $wanted) . '}, got {'
                . implode(', ', $actual) . '}'
            );
        }
    }

    private static function scratchDir(): string {
        $base = sys_get_temp_dir() . '/wprism-adapter-install-' . bin2hex(random_bytes(8));
        if (!mkdir($base, 0700, true) && !is_dir($base)) {
            throw new \RuntimeException("wprism: cannot create a staging directory at $base");
        }
        return $base;
    }

    private static function writeExact(string $path, string $bytes): void {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException("wprism: cannot create $directory");
        }
        if (file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException("wprism: cannot write $path");
        }
    }

    /**
     * Same-directory temp file, then rename.
     *
     * The rename is atomic within one filesystem, which is what makes a reader
     * see either the old file or the new one and never a half-written
     * manifest. Same directory rather than the system temp: a cross-device
     * rename is a copy, and a copy is not atomic.
     */
    private static function atomicWrite(string $path, string $bytes): void {
        $temporary = $path . '.wprism-install-' . bin2hex(random_bytes(6));
        self::writeExact($temporary, $bytes);
        if (!chmod($temporary, 0644) || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException("wprism: cannot publish $path");
        }
    }

    private static function removeTree(string $path): void {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }

    /** @param array<string,mixed> $document */
    private static function encode(array $document): string {
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('wprism: adapter distribution could not encode its report');
        }
        return $json;
    }

    /**
     * The engine's pure surface, in this WordPress-free process.
     *
     * Identical to `AdapterCertify::boot()` and deliberately not extracted: the
     * two commands load different class sets (this one never loads
     * `RepositoryCompiler` or `ScopeAdoption`), and a shared loader would be a
     * place where one verb's dependency quietly becomes the other's.
     */
    private static function boot(): void {
        $repo = dirname(__DIR__, 3);
        $agent = $repo . '/agent/wprism.php';
        if (!is_file($agent)) {
            throw new \RuntimeException("adapter distribution: agent source not found at $agent");
        }
        $source = (string) file_get_contents($agent);
        if (!defined('WPRISM_AGENT_VERSION')) {
            if (preg_match("/define\('WPRISM_AGENT_VERSION', '([^']+)'\)/", $source, $m) !== 1) {
                throw new \RuntimeException('adapter distribution: could not resolve WPRISM_AGENT_VERSION');
            }
            define('WPRISM_AGENT_VERSION', $m[1]);
        }
        if (!defined('WPRISM_SPEC_VERSION')) {
            if (preg_match("/define\('WPRISM_SPEC_VERSION', ([0-9]+)\)/", $source, $m) !== 1) {
                throw new \RuntimeException('adapter distribution: could not resolve WPRISM_SPEC_VERSION');
            }
            define('WPRISM_SPEC_VERSION', (int) $m[1]);
        }
        $classmap = require $repo . '/agent/wprism-classmap.php';
        if (!is_array($classmap)) {
            throw new \RuntimeException('adapter distribution: agent/wprism-classmap.php did not return a map');
        }
        $files = [];
        foreach ($classmap as $path) {
            $files[basename((string) $path, '.php')] = (string) $path;
        }
        foreach (['Canon', 'OptionState', 'ManifestDispositions', 'Policy', 'AdapterCertification'] as $class) {
            $file = $files[$class] ?? null;
            if (!is_string($file)) {
                throw new \RuntimeException(
                    'adapter distribution: agent source ' . $class . '.php is absent from agent/wprism-classmap.php'
                );
            }
            require_once $repo . '/agent/' . $file;
        }
    }

    /** Fail closed, with this command family's exit-2 usage/IO contract. */
    private static function fail(string $message): int {
        fwrite(STDERR, "wprism: adapter: $message\n");
        return 2;
    }
}
