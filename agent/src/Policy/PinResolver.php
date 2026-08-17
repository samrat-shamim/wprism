<?php
namespace Duo;

require_once __DIR__ . '/../Adapter/AdapterSources.php';

/**
 * Manifest-pin normalization and validation, extracted from Policy
 * (DUO-3348 slice 5: the "PinResolver" half of the issue's "ManifestLoader /
 * PinResolver" target seam — `Policy::load()`/`from_snapshot()` themselves,
 * the actual manifest-loading orchestration, stay on Policy; only the
 * pin-specific normalize/validate cluster they call moves here).
 *
 * All three methods were already private STATIC on Policy with every
 * dependency passed as an explicit parameter — never `$this` — so this is a
 * pure relocation, same idiom as ManifestGrammar (DUO-3348 slice 1): no
 * external caller existed (grep-verified across the repo before moving), so
 * Policy needed no compatibility facade, unlike ManifestGrammar's
 * assert_widget_grammar() facade kept for SidebarState.php. Two mechanical
 * changes came with the move, both required by crossing a class boundary
 * rather than any logic change: all three went `private` → `public` (a
 * cross-class caller cannot reach `private`), and validate_manifest_pins()'s
 * own type hint changed from `self $policy` — Policy, while the method lived
 * inside Policy — to `Policy $policy` explicit, since a bare `self` here
 * would now silently mean PinResolver instead.
 *
 * Deliberately does not require_once RepositoryCompiler.php, even though
 * validate_manifest_pins() calls `RepositoryCompiler::resolved_adapters()`:
 * Policy.php did not require it either before this move (grep-verified), so
 * this preserves an existing, pre-DUO-3348 gap rather than introducing a new
 * one — adding the require here would make Policy.php transitively supply
 * RepositoryCompiler.php for the first time, and several existing offline
 * suites plain-`require` RepositoryCompiler.php AFTER their own Policy.php
 * require, which would turn into "Cannot redeclare class" fatals (the exact
 * shape DUO-3348 slice 4 fixed once already, DUO-3440/DUO-3441/DUO-3442's
 * class of bug run in reverse). Fixing the pre-existing gap itself is out of
 * this slice's scope; DUO-3443's planned generic self-require scan is where
 * it belongs. Deliberately does not require_once Policy.php either, for the
 * same reason AdapterRegistry.php doesn't (DUO-3348 slice 4): every Policy
 * reference here is the validate_manifest_pins() type hint, never a
 * `Policy::` static call.
 */
final class PinResolver {
    /**
     * `site.duo.json` originally accepted a flat list of manifest names. A
     * content pin is additive, never a flag day: each entry may instead be
     * {name,digest}, while strings keep their exact historical meaning. Keep
     * the declared digest separate from the loaded manifest so it cannot
     * accidentally participate in policy precedence or the manifest's own
     * content hash.
     *
     * DUO-3314 adds an equally optional `source`. Declaring it asserts WHICH
     * adapter source must answer this pin, and validate_manifest_sources()
     * refuses a mismatch: without it, removing a site-installed adapter and
     * later installing a shipped one under the same name would silently swap
     * which definition a site runs. An unknown key is refused outright rather
     * than ignored — a pin whose author believed it constrained something is
     * the failure this whole record exists to prevent.
     *
     * @return list<array{name:string,digest:?string,source:?string}>
     */
    public static function normalize_manifest_pins($rawPins): array {
        if (!is_array($rawPins) || !array_is_list($rawPins)) {
            throw new \RuntimeException('duo: site.duo.json manifests must be a JSON array');
        }
        $pins = [];
        foreach ($rawPins as $i => $raw) {
            if (is_string($raw) && $raw !== '') {
                AdapterSources::assert_name($raw, "site.duo.json manifests[$i]");
                $pins[] = ['name' => $raw, 'digest' => null, 'source' => null];
                continue;
            }
            if (!is_array($raw) || !is_string($raw['name'] ?? null) || $raw['name'] === '') {
                throw new \RuntimeException(
                    "duo: site.duo.json manifests[$i] must be a non-empty name string or an object with "
                    . 'a non-empty string name and optional digest and source'
                );
            }
            AdapterSources::assert_name($raw['name'], "site.duo.json manifests[$i].name");
            $unknown = array_diff(array_keys($raw), ['name', 'digest', 'source']);
            if ($unknown !== []) {
                throw new \RuntimeException(
                    "duo: site.duo.json manifest '{$raw['name']}' declares unknown pin key(s) "
                    . implode(',', $unknown) . ' — a pin accepts exactly name, digest, and source'
                );
            }
            $digest = $raw['digest'] ?? null;
            if ($digest !== null && (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/', $digest))) {
                throw new \RuntimeException(
                    "duo: site.duo.json manifest '{$raw['name']}' has an invalid digest; expected 64 lowercase "
                    . 'hexadecimal characters'
                );
            }
            $source = $raw['source'] ?? null;
            // DUO-3339 adds the third source word. It is accepted in a pin for
            // the same reason the other two are: validate_manifest_sources()
            // below refuses a pin whose named source stops answering, which is
            // the only thing that makes writing one down worth anything. A
            // `plugin` pin is a deliberate statement that this site runs a
            // definition a plugin bundles — and because precedence ranks the
            // sources, a site or shipped adapter later claiming that name makes
            // the pin refuse loudly rather than silently swapping the winner.
            if ($source !== null && !in_array(
                $source,
                [AdapterSources::SHIPPED, AdapterSources::SITE, AdapterSources::PLUGIN],
                true
            )) {
                throw new \RuntimeException(
                    "duo: site.duo.json manifest '{$raw['name']}' declares source " . var_export($source, true)
                    . ' — the installed adapter sources are "' . AdapterSources::SHIPPED . '", "'
                    . AdapterSources::SITE . '", and "' . AdapterSources::PLUGIN . '"'
                );
            }
            $pins[] = ['name' => $raw['name'], 'digest' => $digest, 'source' => $source];
        }
        return $pins;
    }

    /**
     * A declared pin source is a refusal, not a preference: the overlay is
     * resolved by name, so an operator who wrote down where an adapter comes
     * from must be told when that stops being true rather than quietly served
     * the other source's definition.
     *
     * @param list<array{name:string,digest:?string,source:?string}> $pins
     */
    public static function validate_manifest_sources(array $pins, AdapterSources $sources): void {
        foreach ($pins as $pin) {
            if ($pin['source'] === null) {
                continue;
            }
            // A name nothing installed has no source to disagree with, and
            // source() answers `shipped` by default. Reporting that as "you
            // pinned plugin but it resolves from the shipped source" describes
            // a shipped adapter that does not exist, and — since DUO-3339 —
            // hides the honest answer: file() below throws the plugin
            // source's own recorded refusal for exactly this name, with its
            // remediation, or a not-found naming every source searched.
            if ($sources->path($pin['name']) === null) {
                continue;
            }
            $actual = $sources->source($pin['name']);
            if ($actual !== $pin['source']) {
                throw new \RuntimeException(
                    "duo: manifest '{$pin['name']}' is pinned to the {$pin['source']} adapter source but resolves "
                    . "from the $actual source — review which adapter this site intends to run, then update the "
                    . 'site.duo.json pin'
                );
            }
        }
    }

    /**
     * Compare against DUO-3222's resolved_adapters() result instead of
     * inventing a second digest implementation. Validation happens only
     * after every manifest and cross-manifest contract has passed, so a pin
     * can never turn malformed adapter content into a trusted artifact.
     *
     * @param list<array{name:string,digest:?string,source:?string}> $pins
     */
    public static function validate_manifest_pins(array $pins, Policy $policy): void {
        if (!array_filter($pins, fn($pin) => $pin['digest'] !== null)) {
            return;
        }
        $resolved = RepositoryCompiler::resolved_adapters($policy);
        foreach ($pins as $i => $pin) {
            if ($pin['digest'] === null) {
                continue;
            }
            $actual = (string) ($resolved[$i]['digest'] ?? '');
            if (!hash_equals($pin['digest'], $actual)) {
                throw new \RuntimeException(
                    "duo: manifest '{$pin['name']}' digest mismatch: expected {$pin['digest']}, actual $actual — "
                    . 'review the manifest change, then update its site.duo.json pin'
                );
            }
        }
    }
}
