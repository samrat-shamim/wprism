<?php
namespace WPrism;

/**
 * Pure `site.wprism.json` grammar for the ONE input that can graduate an
 * `outside_version_range` finding: recorded, per-release probe outcomes.
 *
 * ## The cost this exists to remove, and the one it must not create
 *
 * `AdapterContractGrammar` refuses a plugin claim with no exact
 * `version_range`, so every adapter names a closed window; WordPress updates
 * plugins by default, so a window is left behind by an upstream release train
 * nobody at the site chose. The finding that fires is correct — the manifest
 * really has not been certified against those bytes — but it is also the most
 * common thing an operator meets, and its only exits today are "update the
 * range" (a reviewed, fleet-visible two-file edit, AGENTS.md rule 2) or
 * `--force-code-mismatch` (proceed with no evidence at all).
 *
 * A third exit is only honest if it is EVIDENCE-BOUND. The failure mode this
 * file is written against is the one that would make it worthless: assuming
 * benignity when nothing is recorded. So the rule is inverted — absence of
 * evidence is a refusal, never a pass. `LifecyclePlanner::code_mismatch()`
 * mints the graduated verdict only when a recorded probe says, about the exact
 * installed release, what a certification run says: it installed, seeded, and
 * round-tripped byte-identically under this adapter's declared surfaces.
 *
 * ## Why the evidence is a RECORDED document and not a computation
 *
 * The probe that produces one of these rows is a full pair round-trip —
 * fetch the digest-pinned artifact, reset the environment, install the exact
 * version, seed, capture, deploy, apply, recapture, require byte-identity
 * (`cli/src/Adapter/AdapterBoundary.php:73-85`). Nothing on a managed site can
 * perform it, and nothing here tries: this is a reader for what
 * `wprism adapter boundary` already writes. Its four-word outcome vocabulary is
 * restated below rather than imported, because `agent/` never references
 * `cli/` — `sandbox/tests/offline/apply/regress_graduated_version_range.php`
 * asserts the two lists are equal, so the restatement cannot drift silently.
 *
 * `artifact-unresolved` is in the vocabulary and is NOT green: a 404 or a
 * digest mismatch is a fact about a download, not about a plugin, and the
 * bisector refuses to let a mirror outage move a boundary
 * (`AdapterBoundary.php:94-99`). It must not move a deploy verdict either.
 *
 * ## Why the release list travels with the outcomes
 *
 * Outcomes alone cannot answer "is anything in this interval unevidenced?".
 * A site that recorded one green row for the installed release and nothing
 * else would look identical to one that probed every release between the
 * certified window and the installed bytes. The recorded release ORDER is
 * what makes absence detectable, so an entry carries both and the ordering is
 * asserted here the same way the bisector asserts it
 * (`AdapterBoundary.php:695-703`): a mis-ordered list does not fail, it
 * silently answers about the wrong interval.
 *
 * ## What this document can never do
 *
 * It cannot widen a range. An adapter capsule's `package/manifest.json` and
 * Canon-byte-equal `package/disposition.json` restatement remain one reviewed
 * human edit (`ManifestDispositions.php:1110-1112`), and a byte in the
 * capsule's shipped `package/` is fleet-visible (rule 2). This key lives in
 * the site's own policy envelope,
 * which is exactly the scope of the claim it supports: THIS site has recorded
 * probes for THESE releases. It is bound into `site_hash()` and therefore into
 * the compiled artifact — adding evidence is a policy change an artifact must
 * be recompiled for — and deliberately excluded from `state_site_hash()`
 * (`ArtifactPolicyIdentity.php:35-51`), for the same reason the `code`
 * declaration is: it cannot change one byte of canonical state.
 */
final class VersionEvidenceGrammar {
    /**
     * The optional top-level `site.wprism.json` key, keyed by plugin basename —
     * the same keying `Policy::version_ranges()` uses, so the join between a
     * declared range and its evidence is an exact identity rather than a slug
     * match that could bind one plugin's probes to another's contract.
     */
    public const SITE_KEY = 'adapter_version_evidence';

    /**
     * The third verdict itself. Named, never a silent absence of the finding:
     * `outside_version_range` still fires for everything this evidence does
     * not cover, with its message unchanged to the byte (rule 8).
     */
    public const VERDICT = 'version_range_graduated';

    /** The one outcome that means "no declared surface moved at this release". */
    public const OUTCOME_GREEN = 'green';

    /**
     * `AdapterBoundary::OUTCOMES`, restated (see the class docblock for why a
     * restatement rather than a reference, and which suite holds them equal).
     *
     * @var list<string>
     */
    public const OUTCOMES = ['green', 'boot-fatal', 'round-trip-diverges', 'artifact-unresolved'];

    /**
     * Validate the optional evidence block on one decoded site document.
     *
     * Runs on the live and the frozen load path alike
     * (`SitePolicyValidator::validate()`), so a snapshot cannot carry an
     * evidence shape a live load would refuse.
     *
     * @param array<string,mixed> $site
     */
    public static function validate_site_version_evidence(array $site, string $label): void {
        if (!array_key_exists(self::SITE_KEY, $site)) {
            return; // the key is optional; a site with none simply never graduates
        }
        $block = $site[self::SITE_KEY];
        if (!is_array($block) || array_is_list($block)) {
            throw new \RuntimeException(
                "wprism: $label " . self::SITE_KEY . ' must be an object keyed by plugin basename '
                . "(the same keying the manifests' version_range contract uses)"
            );
        }
        foreach ($block as $plugin => $entry) {
            if (!is_string($plugin) || $plugin === '') {
                throw new \RuntimeException(
                    "wprism: $label " . self::SITE_KEY . ' keys must be non-empty plugin basenames'
                );
            }
            self::validate_entry($entry, "$label " . self::SITE_KEY . " '$plugin'");
        }
    }

    /**
     * The validated entry for one plugin, or null when this site has recorded
     * nothing that can speak about it.
     *
     * $manifest is the adapter whose declared window is in question. An entry
     * recorded against a different adapter is not evidence about this one: the
     * probe's round-trip is byte-identity under ONE manifest's declared
     * surfaces, so reusing it under another would be a fabrication — the same
     * reason the bisector refuses an outcome table whose slug disagrees with
     * its release list (`AdapterBoundary.php:717-723`).
     *
     * @param array<string,mixed> $evidence the whole `Policy::adapter_version_evidence()` block
     * @return ?array{slug:string, manifest:string, releases:list<string>, outcomes:array<string,array{version:string,outcome:string,signature:string}>}
     */
    public static function entry(array $evidence, string $plugin, string $manifest): ?array {
        $entry = $evidence[$plugin] ?? null;
        if (!is_array($entry) || array_is_list($entry)) {
            return null;
        }
        if (($entry['manifest'] ?? null) !== $manifest) {
            return null;
        }
        $releases = [];
        foreach ((array) ($entry['releases'] ?? []) as $version) {
            $releases[] = (string) $version;
        }
        $outcomes = [];
        foreach ((array) ($entry['outcomes'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $version = (string) ($row['version'] ?? '');
            $outcomes[$version] = [
                'version' => $version,
                'outcome' => (string) ($row['outcome'] ?? ''),
                'signature' => (string) ($row['signature'] ?? ''),
            ];
        }
        return [
            'slug' => (string) ($entry['slug'] ?? ''),
            'manifest' => (string) $manifest,
            'releases' => $releases,
            'outcomes' => $outcomes,
        ];
    }

    /** @param mixed $entry */
    private static function validate_entry($entry, string $where): void {
        if (!is_array($entry) || array_is_list($entry)) {
            throw new \RuntimeException("wprism: $where must be an object");
        }
        foreach (['manifest', 'slug'] as $identity) {
            $value = $entry[$identity] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw new \RuntimeException(
                    "wprism: $where must carry a non-empty '$identity' — evidence that does not say which adapter "
                    . 'and which upstream plugin it was recorded against cannot be joined to a declared '
                    . 'version_range, and joining it by position would be a fabrication'
                );
            }
        }
        $releases = $entry['releases'] ?? null;
        if (!is_array($releases) || !array_is_list($releases) || $releases === []) {
            throw new \RuntimeException(
                "wprism: $where must carry a non-empty 'releases' list. The recorded release ORDER is what makes "
                . 'an unprobed release detectable: outcomes alone cannot tell a fully-probed interval from one '
                . 'where only the installed version was ever tried'
            );
        }
        $previous = null;
        $seen = [];
        foreach ($releases as $position => $version) {
            $at = "$where releases[$position]";
            if (!is_string($version) || preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]*$/D', $version) !== 1) {
                throw new \RuntimeException("wprism: $at must be a version string in the recorded release-list grammar");
            }
            if (isset($seen[$version])) {
                throw new \RuntimeException("wprism: $at repeats version '$version'; a release appears once");
            }
            $seen[$version] = true;
            // A mis-ordered list does not fail loudly, it answers about the
            // wrong interval — refused by name rather than sorted, exactly as
            // the bisector refuses its own release list (AdapterBoundary.php:695-703).
            if ($previous !== null && version_compare($previous, $version, '>=')) {
                throw new \RuntimeException(
                    "wprism: $at records '$version' after '$previous', but version_compare() orders them the other "
                    . 'way. The list is the release order the graduated verdict walks; a mis-ordered one silently '
                    . 'reports about the wrong interval, so it is refused rather than sorted'
                );
            }
            $previous = $version;
        }
        $outcomes = $entry['outcomes'] ?? null;
        if (!is_array($outcomes) || !array_is_list($outcomes)) {
            throw new \RuntimeException("wprism: $where must carry an 'outcomes' list (it may be empty)");
        }
        $recorded = [];
        foreach ($outcomes as $position => $row) {
            $at = "$where outcomes[$position]";
            if (!is_array($row) || array_is_list($row)) {
                throw new \RuntimeException("wprism: $at must be an object");
            }
            $version = $row['version'] ?? null;
            if (!is_string($version) || !isset($seen[$version])) {
                throw new \RuntimeException(
                    "wprism: $at must carry a 'version' that appears in this entry's own releases list; an outcome "
                    . 'for a release the list does not contain describes an interval this evidence cannot bound'
                );
            }
            if (isset($recorded[$version])) {
                throw new \RuntimeException("wprism: $at repeats version '$version'; a release has one outcome");
            }
            $recorded[$version] = true;
            $outcome = $row['outcome'] ?? null;
            if (!is_string($outcome) || !in_array($outcome, self::OUTCOMES, true)) {
                throw new \RuntimeException(
                    "wprism: $at must carry an 'outcome' of " . implode(' | ', self::OUTCOMES)
                );
            }
            $signature = $row['signature'] ?? null;
            if (!is_string($signature) || trim($signature) === '') {
                throw new \RuntimeException(
                    "wprism: $at must carry a non-empty 'signature'. The verdict this evidence supports is reported "
                    . 'WITH its per-release evidence; an outcome with no signature is a verdict with nothing '
                    . 'behind it, which is the silent pass this whole mechanism refuses to become'
                );
            }
        }
    }
}
