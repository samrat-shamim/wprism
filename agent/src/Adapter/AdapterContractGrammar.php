<?php
namespace Duo;

require_once __DIR__ . '/AdapterSources.php';
// WP-4.12: the {N-1, N} window itself, shared with RepositoryCompiler, which
// judges site.duo.json's own spec_version and cannot reference this layer.
require_once __DIR__ . '/../Kernel/SpecVersionWindow.php';
// WP-6.4: the value grammar for the `declaration_evidence` section. Eager, not
// lazy like AdapterCertification below — that one is deferred because it is one
// of the four names agent/duo.php's bootstrap deliberately does not declare
// (agent/duo.php:124-128); this one is an ordinary sibling with no dependencies
// of its own, and validate_adapter_contract() names it unconditionally.
require_once __DIR__ . '/StructuredEvidence.php';
// WP-6.2: IMPLEMENTED_FEATURES keys one row off
// ManifestGrammar::INVALIDATE_VOCABULARY_FEATURE. Required directly rather than
// leaned on Policy.php's own require below, because a constant expression that
// resolves through a circular include is a load-order bug waiting for the first
// caller that reaches this file first. ManifestGrammar requires nothing itself
// — that is its stated design property — so this costs one stat.
require_once __DIR__ . '/../Policy/ManifestGrammar.php';
// Circular with Policy.php's require_once of this file: safe because
// require_once records the currently included path before the nested require
// is reached, while these methods only resolve Policy at call time.
require_once __DIR__ . '/../Policy/Policy.php';
// WP-5.5: the claim arms and the operator's resolution of a collision between
// two of them. A downward reference (policy is below adapter on the ladder in
// tools/modules.json), so the grammar reads the site-policy section rather
// than the site-policy section reaching up into the grammar.
require_once __DIR__ . '/../Policy/AdapterClaimResolutions.php';

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
 *
 * Since WP-4.3 it is also where the top-level key set is CLOSED for a
 * `spec_version: 3` manifest (§ v3.3): a key in no arm of the signer's own
 * partition and claimed by no implemented feature refuses BY NAME, instead of
 * loading and meaning nothing. The set is not defined here — it is read from
 * `AdapterCertification::topLevelKeyPartition()`, which is what makes the
 * validator and the signer two readers of one definition rather than two lists
 * that agree until they do not.
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
     * After `spec-window/v1`, FOUR post-v3 features shipped through this
     * channel in one wave, and together they are the proof § v3.2's claim
     * holds — each staged a grammar change with `DUO_SPEC_VERSION` left at 3,
     * asserted by each one's own suite:
     *
     *   - `typed-column-codecs/v1` and `attr-id-codecs/v1` (WP-6.1): how one
     *     typed-table column's bytes decode, and the JSON type one block
     *     attribute's resolved id is written back as. Each is a TOP-LEVEL key
     *     rather than a field nested inside `tables`/`block_attrs` for one
     *     reason: a nested field cannot be staged — an engine that predates it
     *     would ignore the field and capture the raw bytes, which is the
     *     silent mis-read this channel exists to convert into a named refusal.
     *   - `structured-evidence/v1` (WP-6.4, spec/repo-format.md § v3.14): the
     *     typed sibling of `notes` that makes the empirical case file
     *     machine-readable.
     *   - `invalidate-vocabulary/v1` (WP-6.2, § v3.15): widens the invalidate[]
     *     verb set INSIDE a section that already exists — the row that shows
     *     `keys` may legitimately be EMPTY, because a feature can widen a value
     *     vocabulary without claiming a new top-level key, and forcing it to
     *     invent one would put a section in the manifest bytes for the sake of
     *     the record's shape.
     *
     * All are keyed at `since` 3 and declared by no shipped manifest, so no
     * adapter digest moves (AGENTS.md rule 2). The whole cost of each was one
     * row in this constant plus its validating collaborator — the entire claim
     * § v3.12 makes when it says the window may one day close: the replacement
     * for a flag day has been walked, four times, before the flag day is
     * retired.
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
        'attr-id-codecs/v1' => [
            'since' => 3,
            'keys' => ['attr_id_codecs'],
        ],
        'spec-window/v1' => [
            'since' => 3,
            'keys' => ['engine_features'],
        ],
        // WP-6.4, and the reason this constant is worth having: the FIRST
        // grammar section to ship after v3, added here and nowhere else, with
        // `DUO_SPEC_VERSION` left at 3. `since` is 3 rather than 4 for the
        // same reason it is 3 for the row above and NOT the version at which
        // the section was written: `since` is the first version whose grammar
        // HAS the section, and this engine's does. A 4 here would refuse the
        // section at every version this engine accepts (assert_section_
        // versions()) and made the next bump a precondition for using it —
        // which is how a channel meant to AVOID a flag day quietly schedules
        // one. `regress_structured_evidence.php` asserts this 3 against the
        // define, so the two cannot drift apart unnoticed.
        'structured-evidence/v1' => [
            'since' => 3,
            'keys' => [StructuredEvidence::SECTION],
        ],
        'typed-column-codecs/v1' => [
            'since' => 3,
            'keys' => ['column_codecs'],
        ],
        // WP-6.2, and the first entry that claims NO top-level key: it widens a
        // VALUE vocabulary inside a section that already exists
        // (`tables.<t>.invalidate[]` gains `{cache_group, cache_key}`,
        // spec/repo-format.md § v3.15). An empty `keys` is therefore the honest
        // record rather than a placeholder — section_min_spec() and
        // admitted_feature_keys() both fold over `keys`, so this row correctly
        // contributes nothing to either, and the partition R-21 counts does not
        // move. `since: 3` is not decorative: the channel that carries the name
        // is itself a v3-only section, so an engine reads this feature exactly
        // when a manifest can declare it.
        //
        // This is the growth § v3.2 promised and § v3.12 names as a condition
        // for ever closing the window — a grammar change shipped post-v3 with
        // no version integer moving anywhere. The gate that consumes it is
        // ManifestGrammar::assert_invalidate_feature_gate(); the name is read
        // from there rather than spelled here because Policy is below Adapter
        // on tools/modules.json's ladder and one definition cannot drift.
        ManifestGrammar::INVALIDATE_VOCABULARY_FEATURE => [
            'since' => 3,
            'keys' => [],
        ],
    ];

    /**
     * The first `spec_version` at which the top-level key set is CLOSED
     * (spec/repo-format.md § v3.3, WP-4.3).
     *
     * 3 and not 2, and that is the whole flag-day safety of this rule: a v2
     * manifest keeps the open behaviour byte for byte, so none of the 16
     * shipped manifests changes behaviour, no manifest byte moves and no
     * adapter digest moves (AGENTS.md rule 2). That is still true after
     * WP-4.12's flip, and it is the no-restamp rule (§ v3.12) that keeps it
     * true: the whole library still declares 2, which is BELOW this gate even
     * though `DUO_SPEC_VERSION` is now 3.
     *
     * What the flip changed is reachability. At DUO_SPEC_VERSION 2 the branch
     * below could not be reached through the product path at all — the window
     * {1, 2} refused a v3 manifest wholesale one step earlier — so the rule was
     * measured against a synthetic N+1 engine. It is now live for any manifest
     * that declares 3, and `regress_closed_top_level_keys.php` reads both arms
     * in one run of the real `duo manifest-validate`. Its N+1 tree is kept for
     * the one thing the shipped engine still cannot show: at N = 4 the window's
     * floor is this gate, so the open era stops existing.
     */
    private const CLOSED_KEY_SET_SINCE = 3;

    /**
     * The RESERVED top-level key: the executable adapter lane's attachment
     * point (spec/repo-format.md § v3.10, WP-4.11).
     *
     * Reserved means REFUSED BY NAME, never admitted-and-ignored. `package` is
     * in no arm of `AdapterCertification::topLevelKeyPartition()` and no
     * implemented `engine_features` value claims it, so a `spec_version: 3`
     * manifest declaring it is refused by `assert_top_level_keys()` either way
     * — with or without this constant. What the reservation buys is WHICH
     * refusal: "this engine does not recognise 'package', correct the spelling"
     * is false and sends the author to invent a feature name they may not mint
     * (§ v3.2), while the message below names the gate that decides. Nothing
     * about the verdict moves, which is the property WP-7.1's later opening
     * rests on: the lane opens as a policy flip proven by
     * `sandbox/tests/offline/adapter/regress_v3_reservations.php`, never as a
     * format break (§ v3.11 condition 7).
     */
    public const RESERVED_PACKAGE_KEY = 'package';

    /**
     * The `spec_version` integers this engine accepts: exactly N and N-1.
     *
     * The floor is N-1 and never deeper, so an N-2 manifest can never
     * accumulate by inattention; `tools/wire-surface.php` asserts that equality
     * under `make release-gate` by probing this validator rather than by
     * reading this line (spec/repo-format.md § v3.1).
     *
     * DELEGATED SINCE WP-4.12. `site.duo.json` carries the same wire version
     * integer and `RepositoryCompiler::compile()` now judges it against the
     * same window, but `Repository` is layer 3 and this file is layer 5, so
     * the compiler cannot reference it. The definition moved down to
     * `SpecVersionWindow` (kernel), which both readers already depend on;
     * this method stays because every refusal in this file is written against
     * it and because the release gate probes THIS validator for the floor.
     *
     * @return list<int>
     */
    private static function accepted_window(int $supported): array {
        return SpecVersionWindow::accepted($supported);
    }

    /**
     * The window as it is printed in a refusal: `{2, 3}`.
     *
     * @param list<int> $accepted
     */
    private static function window_text(array $accepted): string {
        return SpecVersionWindow::text($accepted);
    }

    /**
     * The first `spec_version` at which the top-level key set is CLOSED.
     *
     * Public since WP-4.12, for one caller and one reason. `duo adapter-draft`
     * emits a manifest that CARRIES `_draft`, and this rule refuses that key at
     * or above this version by design (§ v3.3: an authoring artifact must not
     * enter the identity row a certificate covers). Before the flip the two
     * could not collide — the engine's window topped out one below this gate,
     * so every draft it emitted was admissible. After the flip they collide by
     * default, and the generator has to choose its stamp from the rule rather
     * than from a literal that would rot the next time either moves.
     *
     * Exposing the number is not exposing a decision: nothing may relax the
     * gate, and `AdapterDraft` uses it only to pick the highest ACCEPTED
     * version that still admits its sidecar, refusing loudly when none does.
     */
    public static function closed_key_set_since(): int {
        return self::CLOSED_KEY_SET_SINCE;
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
     * Every top-level key a `spec_version: 3` manifest may declare.
     *
     * ONE definition, read from the signer rather than restated:
     * `AdapterCertification::topLevelKeyPartition()` (WP-4.1) is the base set,
     * and the manifest's own declared, implemented features add the keys they
     * claim (§ v3.2's growth rule). The alternative — a list typed here —
     * equals the partition on the day it is typed and stops equalling it on the
     * day the engine moves, which is the day nobody is looking; `php
     * tools/wire-surface.php --check` asserts the equality under `make
     * release-gate` (register row R-21) so that it cannot be reintroduced.
     *
     * The require is lazy, and deliberately: `AdapterCertification` is one of
     * the four names `agent/duo.php`'s bootstrap does NOT declare, and it is
     * require_once'd at each use site instead (agent/duo.php:124-128). A v2
     * manifest never reaches this method, so the open v2 era loads exactly the
     * files it loads today; the require is here rather than at the top of the
     * file for that reason and no other.
     *
     * @param array<string,mixed> $manifest
     * @return list<string>
     */
    public static function admitted_top_level_keys(array $manifest): array {
        require_once __DIR__ . '/AdapterCertification.php';
        $partition = AdapterCertification::topLevelKeyPartition();
        $admitted = array_merge(
            $partition['entity_sections'],
            $partition['field_sections'],
            $partition['non_surface_keys'],
            self::admitted_feature_keys($manifest)
        );
        $admitted = array_values(array_unique($admitted));
        sort($admitted, SORT_STRING);

        return $admitted;
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
        // AFTER the feature channel and BEFORE every value check below, and
        // both halves of that placement are the contract. After, because §
        // v3.3's three verdicts must stay distinct: a key claimed by a feature
        // this engine LACKS has to refuse by FEATURE name (assert_engine_
        // features(), one line up), never as a typo naming the key. Before,
        // because whether a key EXISTS is a different question from whether its
        // value is well-formed — an author who misspelled a section should be
        // told that, not handed a refusal about the contents of a section the
        // engine does not have.
        if ($spec >= self::CLOSED_KEY_SET_SINCE) {
            self::assert_top_level_keys($name, $spec, $manifest);
        }
        // WP-6.4, and FIRST among the value checks because it is the one that
        // can only be reached by walking the whole channel: the section exists
        // for this engine (assert_section_versions()), the feature that claims
        // it was declared and is implemented (assert_engine_features()), and
        // the closed key set admitted the key on that basis
        // (assert_top_level_keys()). Anything wrong before this line is a
        // question about whether the section EXISTS; from here on it is a
        // question about what is inside it, and those are not the same refusal.
        StructuredEvidence::assert_section($name, $manifest);
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
            // it is not, saying "declare spec_version $since" would send them
            // to a manifest this same validator refuses wholesale one line
            // above. WP-4.12 moved this engine from the second era into the
            // first: at DUO_SPEC_VERSION 2 the only implemented section
            // (`engine_features`, since 3) sat one past the ceiling and BOTH
            // in-window versions refused it; the flip put 3 inside the window,
            // so a v2 manifest now gets the actionable remedy and a v3 one is
            // simply admitted. Both arms stay, because the next section
            // declared at a version this engine does not reach re-enters the
            // second era on the day it is added.
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
     * The closed top-level key set (spec/repo-format.md § v3.3, WP-4.3).
     *
     * WHAT THIS REPLACES. The set already existed, was already maintained and
     * already refused — but only in `AdapterCertification::siteRatification()`,
     * which most authors reach long after the typo. So a manifest carrying
     * `totally_made_up_section` and a transposed `optoins` validated `[ok]` and
     * was then unsignable, measured both ways in
     * `sandbox/tests/offline/policy/regress_spec_v3_dry_run.php` under rule
     * V3-KEYS. That silence is the failure ManifestGrammar.php:50-56 already
     * states one level down for a table `class` value: an unrecognised
     * declaration that means nothing is indistinguishable from a deliberate
     * one, which is how a whole plugin's authored rows go missing from
     * canonical state because of one transposed letter.
     *
     * WHY IT IS SAFE TO CLOSE. Because the set can GROW without a flag day. A
     * closed set that cannot grow is simply the next flag day deferred, so §
     * v3.2's channel is the growth rule: a key claimed by a declared feature
     * this engine IMPLEMENTS is admitted (`admitted_top_level_keys()`), a key
     * claimed by a declared feature it does NOT implement has already refused
     * by feature name, and a key nothing claims refuses here. Three verdicts,
     * no fourth — and in particular no "unknown keys are ignored", which is the
     * behaviour v3 removes.
     *
     * WHY THE REFUSAL NAMES EVERY OFFENDING KEY. `sort()` and then all of them,
     * not the first: an author who transposed one letter in two sections would
     * otherwise pay two round trips to learn two facts the engine knew at once,
     * and a refusal whose content depends on PHP's key order is not a refusal a
     * harness can pin.
     *
     * @param array<string,mixed> $manifest
     */
    private static function assert_top_level_keys(string $name, int $spec, array $manifest): void {
        $admitted = self::admitted_top_level_keys($manifest);
        $unknown = array_values(array_diff(array_map('strval', array_keys($manifest)), $admitted));
        if ($unknown === []) {
            return;
        }
        sort($unknown, SORT_STRING);

        // `_draft` gets its own sentence, and it wins over every other unknown
        // key, because it says something about the whole document rather than
        // about one section: this is `duo adapter-draft` output
        // (cli/src/Adapter/AdapterDraft.php:379), and the first thing its author
        // has to do is strip the sidecar and re-validate — at which point any
        // remaining unrecognised key is reported with the remedy that fits it.
        // Its own remedy is the opposite of the general one: nothing is
        // misspelled and nothing is missing an engine feature, and telling that
        // author to "declare the feature that claims it" would send them to
        // invent a feature name they may not mint (§ v3.2). Admitting it is
        // what § v3.3 refuses on the merits: the key would land inside the
        // identity row every certificate covers
        // (ArtifactPolicyIdentity::manifest_rows()), putting unreviewed
        // proposals under a signature.
        if (in_array('_draft', $unknown, true)) {
            throw new \RuntimeException(
                "duo: manifest '$name' declares spec_version $spec and the top-level key '_draft' — that is the "
                . 'proposal sidecar `duo adapter-draft` writes for a human reviewer, and it is an authoring '
                . 'artifact rather than a declaration: admitting it would put unreviewed proposals inside the '
                . 'identity row every certificate covers (spec/repo-format.md § v3.3). Remedy: strip the `_draft` '
                . 'key before install — `duo manifest-validate` reports the sidecar\'s facts, proposals and '
                . 'unsupported counts on every run, so nothing in it is lost by removing it'
            );
        }

        // The exec lane's reserved slot (§ v3.10, WP-4.11), placed AFTER
        // `_draft` for the reason that case gives — a draft sidecar is a fact
        // about the whole document and has to be stripped before anything else
        // in it is worth reading — and BEFORE the general refusal because the
        // general refusal would be a lie: `package` is not a misspelling and
        // there is no `engine_features` value an author may declare to admit
        // it. The gate, not the spelling, is what decides, and the message says
        // so. The verdict is unchanged in both directions: refused before this
        // rider, refused after it, same exception, same load failure.
        if (in_array(self::RESERVED_PACKAGE_KEY, $unknown, true)) {
            throw new \RuntimeException(
                // The pinned phrase is written CONTIGUOUSLY, never split across
                // a concatenation, so `grep` and the document suite find the
                // spec's own sentence in the shipped bytes.
                "duo: manifest '$name' declares '" . self::RESERVED_PACKAGE_KEY
                . "' — the executable adapter lane is reserved and shut."
                . ' It opens only at gate G5 (spec/repo-format.md § v3.11), never by declaring the key'
            );
        }

        throw new \RuntimeException(
            "duo: manifest '$name' declares spec_version $spec and the top-level "
            . (count($unknown) === 1 ? 'key ' : 'keys ')
            . implode(', ', array_map(static fn(string $k): string => "'" . $k . "'", $unknown))
            . ', which this engine does not recognise — at spec_version ' . self::CLOSED_KEY_SET_SINCE
            . ' the top-level key set is CLOSED, so an unrecognised section is a misspelling rather than an inert '
            . 'marker (spec/repo-format.md § v3.3). Remedy: correct the spelling, remove the section, or declare '
            . 'the `engine_features` value that claims it (§ v3.2) — this engine implements: '
            . implode(', ', self::implemented_features())
        );
    }

    /**
     * Reject load-order-dependent ownership when two pinned manifests name
     * the same plugin or theme with different compatibility ranges. Identical
     * ranges remain redundant but deterministic and are intentionally allowed.
     *
     * WP-5.5 adds the one way out, and it is the operator's rather than an
     * adapter's: `site.duo.json` `policy.adapter_claims` names WHICH claimant
     * is in force for that plugin or theme (spec/repo-format.md § v3.13), and
     * a collision so resolved is not ambiguous any more — pin order decides
     * nothing, a written decision does. The displaced claimant is reported by
     * `Policy::displaced_adapter_claims()`, never hidden, and its manifest
     * stays pinned and loaded: this resolves a CLAIM, it does not unload an
     * adapter or merge two claims into one.
     *
     * Everything about the unresolved case is unchanged, deliberately and
     * byte for byte: `$resolutions` empty is every repository that existed
     * before this section, and the message below is the one they have always
     * received. That is what makes the resolution an opt-in operator decision
     * instead of a relaxation — an undeclared conflict still refuses.
     *
     * The refusal text keeps its "conflicting ownership with no v2 composition
     * rule" sentence rather than advertising the new section, because it is
     * still true and still the right advice: composition does not exist, at v2
     * or at v3. The resolution is not composition and does not become the
     * first remedy an operator reaches for — pinning one, or narrowing a range
     * to a disjoint window, remains a better answer whenever it is available.
     *
     * @param list<array<string,mixed>> $manifests
     * @param array<string,array<string,array{in_force:string,note:?string}>> $resolutions AdapterClaimResolutions::declared()
     */
    public static function validate_no_conflicting_adapter_claims(array $manifests, array $resolutions): void {
        foreach (AdapterClaimResolutions::CLAIM_ARMS as $idKey => $rangeKey) {
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
                    if ($prev['range'] != $range
                        && !AdapterClaimResolutions::resolves($resolutions, $idKey, $id)
                    ) {
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
