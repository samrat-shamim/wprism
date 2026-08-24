<?php
namespace Duo;

require_once __DIR__ . '/AdapterSources.php';
// Circular with Policy.php's require_once of this file: safe because
// require_once records the currently included path before the nested require
// is reached, while these methods only resolve Policy at call time.
require_once __DIR__ . '/../Policy/Policy.php';

/**
 * Pure adapter compatibility contract grammar.
 *
 * This collaborator validates per-manifest plugin/theme/spec/interpreter
 * claims and the cross-manifest no-conflicting-ownership guard. It never
 * reads the live environment or runs provider code. Policy retains the
 * shared range predicate and loader/runtime/reporting orchestration.
 *
 * It is also where the wire version is decided, and since WP-4.2 that is a
 * WINDOW rather than an equality: a manifest declaring `DUO_SPEC_VERSION` or
 * the one version before it is accepted, an integer outside that window refuses
 * naming the window, and a manifest inside it that declares a section this
 * engine implements only at a higher version refuses naming the section
 * (spec/repo-format.md § v3.1). The point is that every later format change can
 * stage through the window, or through the per-adapter `engine_features`
 * channel below (§ v3.2), one adapter at a time — so v3 is meant to be the last
 * flag day rather than one of a series.
 */
final class AdapterContractGrammar {
    /**
     * Engine features this engine IMPLEMENTS, and what each one claims.
     *
     * ONE definition carrying both facts a feature decides, because they can
     * never be allowed to disagree: `since` is the first `spec_version` at
     * which the feature's sections exist, and `keys` are the top-level
     * manifest sections the feature claims. § v3.1's per-section refusal reads
     * the minimum version of a section straight out of these rows
     * (section_min_spec()), and § v3.2's channel answers "does this engine
     * implement the name this adapter declared" out of the same rows — so a
     * feature cannot be implemented with its section unknown, or the reverse.
     *
     * `spec-window/v1` is the first entry and is implemented BY THIS FILE: the
     * N/N-1 acceptance window plus the declaration channel itself. That is what
     * makes the channel a live product path on the day it ships rather than an
     * admissibility argument — the failure mode `authored_typed_snapshot_
     * post_v1` demonstrates, which is declared by nothing across all 16 shipped
     * manifests.
     *
     * Feature names are ENGINE-OWNED: an adapter declares one, never mints one
     * (spec/repo-format.md § v3.2). A name is also permanent, which is why
     * docs/wire-surface.md carries it as row R-19: a declared name lives inside
     * the manifest bytes ArtifactPolicyIdentity::manifest_rows() folds into the
     * adapter digest that every `site.duo.json` pin and every certificate
     * binds, so renaming one moves the digest of every manifest declaring it.
     *
     * @var array<string,array{since:int,keys:list<string>}>
     */
    private const IMPLEMENTED_FEATURES = [
        'spec-window/v1' => [
            'since' => 3,
            'keys' => ['engine_features'],
        ],
    ];

    /**
     * The `spec_version` integers this engine accepts: exactly N and N-1.
     *
     * The floor is N-1 and never deeper, so an N-2 manifest can never
     * accumulate by inattention; `tools/wire-surface.php` asserts that equality
     * under `make release-gate` by probing this validator rather than by
     * reading this line (spec/repo-format.md § v3.1).
     *
     * @return list<int>
     */
    private static function accepted_window(int $supported): array {
        return [$supported - 1, $supported];
    }

    /**
     * The window as it is printed in a refusal: `{1, 2}`.
     *
     * @param list<int> $accepted
     */
    private static function window_text(array $accepted): string {
        return '{' . implode(', ', $accepted) . '}';
    }

    /**
     * Every top-level manifest section this engine implements only at some
     * spec_version, mapped to the first version that has it.
     *
     * Derived from IMPLEMENTED_FEATURES so the two can never drift, and
     * ksorted so the refusal a multi-section manifest gets is the same one on
     * every run — refusal ORDER is observable contract here exactly as it is in
     * ManifestValidator::validate_manifest() (:40-42).
     *
     * @return array<string,int>
     */
    public static function section_min_spec(): array {
        $out = [];
        foreach (self::IMPLEMENTED_FEATURES as $row) {
            foreach ($row['keys'] as $key) {
                $out[$key] = isset($out[$key]) ? min($out[$key], $row['since']) : $row['since'];
            }
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * The engine feature names this engine implements, sorted.
     *
     * @return list<string>
     */
    public static function implemented_features(): array {
        $names = array_keys(self::IMPLEMENTED_FEATURES);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The top-level sections a manifest's DECLARED features admit.
     *
     * The other half of § v3.2's channel, and the seam § v3.3's closed key set
     * (WP-4.3) attaches to: a key claimed by a declared feature the engine
     * IMPLEMENTS is admitted. A key claimed by a feature this engine does not
     * have never reaches here — validate_adapter_contract() has already refused
     * that manifest by feature name — so this answers only for a manifest the
     * contract grammar accepted.
     *
     * @param array<string,mixed> $manifest
     * @return list<string>
     */
    public static function admitted_feature_keys(array $manifest): array {
        $keys = [];
        foreach ((array) ($manifest['engine_features'] ?? []) as $feature) {
            if (!is_string($feature) || !isset(self::IMPLEMENTED_FEATURES[$feature])) {
                continue;
            }
            foreach (self::IMPLEMENTED_FEATURES[$feature]['keys'] as $key) {
                $keys[$key] = true;
            }
        }
        $out = array_keys($keys);
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * Validate one manifest's adapter compatibility contract.
     */
    public static function validate_adapter_contract(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        $spec = $manifest['spec_version'] ?? null;
        $supported = defined('DUO_SPEC_VERSION') ? DUO_SPEC_VERSION : 0;
        // Absent or non-integer keeps DUO-3247's refusal byte for byte, and
        // deliberately so: it is not a version, so there is no window for it to
        // be inside and nothing about the window is worth printing at it
        // (spec/repo-format.md § v3.1).
        if (!is_int($spec)) {
            $declared = $spec === null ? 'no spec_version' : ('spec_version ' . var_export($spec, true));
            throw new \RuntimeException(
                "duo: manifest '$name' declares $declared"
                . " but this engine requires spec_version $supported — pin a compatible manifest or update it"
            );
        }
        $accepted = self::accepted_window($supported);
        if (!in_array($spec, $accepted, true)) {
            throw new \RuntimeException(
                "duo: manifest '$name' declares spec_version " . var_export($spec, true)
                . ' but this engine accepts spec_version ' . self::window_text($accepted)
                . " — the acceptance window is exactly N and N-1, where N is this engine's DUO_SPEC_VERSION"
                . ' (spec/repo-format.md § v3.1) — pin a compatible manifest or update it'
            );
        }
        self::assert_section_versions($name, $spec, $manifest, $accepted);
        self::assert_engine_features($name, $manifest);
        // Validate the interpreter name at load rather than waiting for the
        // lazy interpreters() lookup to hand a non-string to preg_match().
        if (array_key_exists('interpreter', $manifest) && $manifest['interpreter'] !== null) {
            $interpreter = $manifest['interpreter'];
            if (!is_string($interpreter) || preg_match('/^[a-z0-9_-]+$/D', $interpreter) !== 1) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares interpreter " . var_export($interpreter, true)
                    . ' — an interpreter name must be a non-empty string matching ^[a-z0-9_-]+$, since it resolves '
                    . 'to <manifests_dir>/interpreters/<name>.php'
                );
            }
        }
        foreach ([['plugin', 'version_range'], ['theme', 'theme_version_range']] as [$idKey, $rangeKey]) {
            $id = $manifest[$idKey] ?? null;
            if ($id === null) {
                continue;
            }
            if (!is_string($id) || $id === '') {
                throw new \RuntimeException("duo: manifest '$name' declares a non-string or empty '$idKey'");
            }
            if ($idKey === 'plugin') {
                AdapterSources::assert_plugin_basename($id, "manifest '$name' declares 'plugin'");
            }
            // The identifier is later concatenated into filesystem paths by
            // code-version consumers, so reject traversing or absolute theme
            // identities before any consumer sees them.
            $segments = explode('/', $id);
            $depthOk = $idKey === 'plugin' ? count($segments) <= 2 : count($segments) === 1;
            if ($idKey !== 'plugin' && (!$depthOk || $id[0] === '/' || str_contains($id, '\\')
                || in_array('..', $segments, true) || in_array('.', $segments, true)
                || in_array('', $segments, true))) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares '$idKey' " . var_export($id, true)
                    . ' — a ' . $idKey . ' identifier is '
                    . ($idKey === 'plugin' ? "'<directory>/<file>.php' or '<file>.php'" : 'a bare directory slug')
                    . ', never an absolute path and never one containing a ".." segment; it is concatenated into '
                    . 'filesystem paths by the code-half version checks'
                );
            }
            $range = $manifest[$rangeKey] ?? null;
            if (!is_array($range)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares '$idKey' ('$id') but no '$rangeKey' — an adapter naming a "
                    . "$idKey with no exact version range is unbounded support, which this project's contract "
                    . 'forbids (DUO-3222). Declare {"min":..,"max":..} or drop the ' . "$idKey claim."
                );
            }
            Policy::assert_min_max_range($range, "manifest '$name' declares '$rangeKey'");
        }
    }

    /**
     * A manifest inside the window may not use a section from a HIGHER version.
     *
     * This is the refusal that makes the window a staging channel rather than
     * a tolerance. The alternative — ignoring a section the declared version
     * does not have — is the failure "Vocabulary ownership and extension"
     * already states for values: an unrecognised declaration that means nothing
     * is indistinguishable from a deliberate one, which is how a transposed
     * letter drops a plugin's authored rows out of canonical state with no
     * diagnostic anywhere.
     *
     * The refusal names the SECTION and the ADAPTER, never just "this engine
     * requires spec_version N", because those are the two facts an operator
     * needs to decide whether to edit a manifest or move an engine. Its blast
     * radius is the blast radius the SOURCE already grants: `duo
     * manifest-validate` loads every manifest on its own and prints a verdict
     * per manifest, AdapterSources::grammar_verdict() judges one adapter at a
     * time for the survey, and the plugin source records a per-adapter refusal
     * row (AdapterSources.php:2145, SCOPE_ADAPTER). A PINNED adapter still
     * refuses the load, exactly as every other manifest grammar refusal does —
     * dropping a pinned adapter silently would BE the state-loss this rule
     * exists to prevent.
     *
     * @param array<string,mixed> $manifest
     * @param list<int> $accepted
     */
    private static function assert_section_versions(
        string $name,
        int $spec,
        array $manifest,
        array $accepted
    ): void {
        foreach (self::section_min_spec() as $section => $since) {
            if ($spec >= $since || !array_key_exists($section, $manifest)) {
                continue;
            }
            // Two eras, two honest remedies. While the section's version is
            // itself inside the window an author can simply declare it; while
            // it is not (this engine at DUO_SPEC_VERSION 2 and the section at
            // 3), saying "declare spec_version 3" would send them to a manifest
            // this same validator refuses wholesale one line above.
            $remedy = in_array($since, $accepted, true)
                ? "declare spec_version $since to use it, or remove the section"
                : "this engine's window does not reach spec_version $since, so remove the section or run an "
                    . 'engine whose window does';
            throw new \RuntimeException(
                "duo: manifest '$name' declares spec_version $spec and the section '$section', which this "
                . "engine implements only at spec_version $since — a manifest inside the acceptance window "
                . self::window_text($accepted) . ' may not declare a section from a HIGHER version '
                . '(spec/repo-format.md § v3.1). Remedy: ' . $remedy
            );
        }
    }

    /**
     * The `engine_features` declaration channel (spec/repo-format.md § v3.2).
     *
     * An engine that implements every listed feature loads the adapter; one
     * that lacks a listed feature refuses THAT ADAPTER, naming the feature. The
     * point is that a post-v3 primitive ships as a feature name, a manifest key
     * the feature claims, and a refusal for the engine that does not have it —
     * so an older engine meeting a manifest that uses the primitive says so by
     * name instead of mis-reading the declaration, and no version integer moves
     * anywhere. A name nothing implements is refused as unimplemented rather
     * than admitted as forward-looking; that is what distinguishes this channel
     * from `authored_typed_snapshot_post_v1`, the declared-but-not-implemented
     * marker whose one honest property is that it captures nothing and says so.
     *
     * Shape is checked before vocabulary, and strictly: an unreadable
     * declaration cannot be compared against the vocabulary at all, and
     * admitting it would reintroduce the silence this channel replaces.
     *
     * @param array<string,mixed> $manifest
     */
    private static function assert_engine_features(string $name, array $manifest): void {
        if (!array_key_exists('engine_features', $manifest)) {
            return;
        }
        $declared = $manifest['engine_features'];
        $wellShaped = is_array($declared) && $declared !== [] && array_is_list($declared);
        if ($wellShaped) {
            foreach ($declared as $feature) {
                if (!is_string($feature) || $feature === '') {
                    $wellShaped = false;
                    break;
                }
            }
        }
        if ($wellShaped) {
            /** @var list<string> $declared */
            $canonical = array_values(array_unique($declared));
            sort($canonical, SORT_STRING);
            $wellShaped = $canonical === $declared;
        }
        if (!$wellShaped) {
            // Rendered as JSON, not var_export: the declaration arrived as
            // JSON, and var_export of an array is multi-line — a refusal that
            // spans lines is unreadable in a WP-CLI error and unmatchable by
            // the harnesses that pin these strings.
            $shown = json_encode($declared, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            throw new \RuntimeException(
                "duo: manifest '$name' declares 'engine_features' "
                . (is_string($shown) ? $shown : var_export($declared, true))
                . ' — engine_features is a non-empty, sorted, duplicate-free list of engine feature name '
                . 'strings (spec/repo-format.md § v3.2)'
            );
        }
        $implemented = self::implemented_features();
        foreach ($declared as $feature) {
            if (in_array($feature, $implemented, true)) {
                continue;
            }
            throw new \RuntimeException(
                "duo: manifest '$name' declares engine feature " . var_export($feature, true)
                . ' — this engine does not implement it. Feature names are engine-owned: an adapter declares '
                . 'one, never mints one (spec/repo-format.md § v3.2). This engine implements: '
                . implode(', ', $implemented)
                . '. Remedy: drop the declaration, or run an engine that has the feature'
            );
        }
    }

    /**
     * Reject load-order-dependent ownership when two pinned manifests name
     * the same plugin or theme with different compatibility ranges. Identical
     * ranges remain redundant but deterministic and are intentionally allowed.
     *
     * @param list<array<string,mixed>> $manifests
     */
    public static function validate_no_conflicting_adapter_claims(array $manifests): void {
        foreach ([['plugin', 'version_range'], ['theme', 'theme_version_range']] as [$idKey, $rangeKey]) {
            $seen = [];
            foreach ($manifests as $m) {
                $id = $m[$idKey] ?? null;
                if (!is_string($id) || $id === '') {
                    continue;
                }
                $range = $m[$rangeKey] ?? [];
                $name = (string) ($m['name'] ?? '?');
                if (isset($seen[$id])) {
                    $prev = $seen[$id];
                    if ($prev['range'] != $range) {
                        throw new \RuntimeException(
                            "duo: manifests '{$prev['name']}' and '$name' both declare $idKey '$id' with "
                            . "different $rangeKey values (" . json_encode($prev['range']) . ' vs '
                            . json_encode($range) . ') — conflicting ownership with no v2 composition rule; '
                            . 'pin only one, or narrow one range to a disjoint window'
                        );
                    }
                    continue;
                }
                $seen[$id] = ['name' => $name, 'range' => $range];
            }
        }
    }
}
