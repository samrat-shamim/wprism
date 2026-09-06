<?php
namespace WPrism;

// WP-6.5: the feature name and the body-mode value `structured-body-refs/v1`
// admits, read from the one file that defines them rather than restated here —
// two spellings of one gated value agree until the day one of them moves.
require_once __DIR__ . '/BodyRefGrammar.php';

/**
 * The pure post-type behavior grammar extracted from Policy.php
 * (issue #3348): the closed `post_types.<type>.body` and `.phase` switches and
 * the direct `post_types.<parent>.children` relationship declaration, and
 * the `post_types.<type>.regen_dependency` shape declaration.
 *
 * These values name engine behavior, not adapter-owned data. Keeping their
 * declaration validation beside the vocabulary makes a typo fail while the
 * manifest is still offline bytes, instead of silently falling back in the
 * runtime lookup and changing capture/apply semantics.
 *
 * Policy keeps the public body_mode()/post_type_phase() lookup contract, but
 * its load()/from_snapshot() paths call these validators directly. No engine
 * or WordPress class is needed here, so the grammar remains independently
 * loadable for offline checks.
 */
final class PostTypeGrammar {
    /**
     * Validate the direct CPT parent/child declaration grammar. This describes
     * only wp_posts.post_parent edges; it is not a cascade or discovery
     * grammar. Every endpoint must be declared in the same manifest so a
     * typo cannot reach a target query.
     */
    public static function validate_post_type_children(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        $postTypes = (array) ($manifest['post_types'] ?? []);
        foreach ($postTypes as $parentPostType => $decl) {
            if (!is_array($decl) || !array_key_exists('children', $decl)) {
                continue;
            }
            if (!is_string($parentPostType)
                || !preg_match('/^[a-z0-9_-]{1,20}$/', $parentPostType)) {
                throw new \RuntimeException(
                    "wprism: manifest '$name' post_types key " . var_export($parentPostType, true)
                    . ' cannot declare children: expected a WordPress post-type name'
                );
            }
            $children = $decl['children'];
            if (!is_array($children) || !array_is_list($children) || !$children) {
                throw new \RuntimeException(
                    "wprism: manifest '$name' post_types.$parentPostType.children must be a non-empty list"
                );
            }
            $seen = [];
            foreach ($children as $index => $childPostType) {
                if (!is_string($childPostType)
                    || !preg_match('/^[a-z0-9_-]{1,20}$/', $childPostType)) {
                    throw new \RuntimeException(
                        "wprism: manifest '$name' post_types.$parentPostType.children[$index] "
                        . 'must be a WordPress post-type name'
                    );
                }
                if ($childPostType === $parentPostType) {
                    throw new \RuntimeException(
                        "wprism: manifest '$name' post_types.$parentPostType.children "
                        . 'cannot declare a CPT as its own child'
                    );
                }
                if (!array_key_exists($childPostType, $postTypes)) {
                    throw new \RuntimeException(
                        "wprism: manifest '$name' post_types.$parentPostType.children[$index] "
                        . "names undeclared child CPT '$childPostType'"
                    );
                }
                if (isset($seen[$childPostType])) {
                    throw new \RuntimeException(
                        "wprism: manifest '$name' post_types.$parentPostType.children "
                        . "contains duplicate child CPT '$childPostType'"
                    );
                }
                $seen[$childPostType] = true;
            }
        }
    }

    /**
     * Validate `post_types.<type>.regen_dependency` shape at load time
     * (issue #3234). This checks only declaration shape: required keys, scalar
     * types, and the strict top-level/batch key sets. It deliberately never
     * touches the regenerator PHP file; regenerators() keeps that lazy-load
     * and callable-contract responsibility for declarations actually used.
     * The optional `effects` list is admitted here, while its entries remain
     * the responsibility of validate_effect_contracts().
     */
    public static function validate_regen_dependencies(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ($manifest['post_types'] ?? [] as $postType => $decl) {
            $regen = $decl['regen_dependency'] ?? null;
            if ($regen === null) {
                continue;
            }
            if (!is_array($regen) || array_is_list($regen)) {
                throw new \RuntimeException(
                    "wprism: manifest '$name' post_types.$postType.regen_dependency must be an object"
                );
            }
            $regenerator = $regen['regenerator'] ?? null;
            if (!is_string($regenerator) || $regenerator === '') {
                throw new \RuntimeException(
                    "wprism: manifest '$name' post_types.$postType.regen_dependency needs a non-empty string 'regenerator'"
                );
            }
            if (preg_match('/^[a-z][a-z0-9_-]*$/D', $regenerator) !== 1) {
                throw new \RuntimeException(
                    "wprism: manifest '$name' post_types.$postType.regen_dependency.regenerator must match "
                    . '^[a-z][a-z0-9_-]*$'
                );
            }
            $verify = $regen['verify'] ?? null;
            if (!is_array($verify) || !is_string($verify['table'] ?? null) || ($verify['table'] ?? '') === ''
                || !is_string($verify['column'] ?? null) || ($verify['column'] ?? '') === '') {
                throw new \RuntimeException(
                    "wprism: manifest '$name' post_types.$postType.regen_dependency needs "
                    . 'verify: {table: <non-empty string>, column: <non-empty string>}'
                );
            }

            $unknown = array_diff(
                array_keys($regen),
                ['regenerator', 'verify', 'batch', 'refresh', 'always_on_write', 'effects']
            );
            if ($unknown) {
                throw new \RuntimeException(
                    "wprism: manifest '$name' post_types.$postType.regen_dependency contains unknown key(s): "
                    . implode(', ', $unknown)
                );
            }
            if (array_key_exists('batch', $regen) && array_key_exists('refresh', $regen)) {
                throw new \RuntimeException(
                    "wprism: manifest '$name' post_types.$postType.regen_dependency cannot declare both 'batch' and 'refresh'"
                );
            }
            if (array_key_exists('always_on_write', $regen)
                && (array_key_exists('batch', $regen) || array_key_exists('refresh', $regen))) {
                throw new \RuntimeException(
                    "wprism: manifest '$name' post_types.$postType.regen_dependency.always_on_write is ambiguous beside batch/refresh"
                );
            }

            // x: batch/refresh is deliberately opt-in. Existing
            // declarations (TEC included) retain the missing-row,
            // regenerate(int) behavior above. Accept both names as a small
            // compatibility affordance for manifest authors: "refresh"
            // describes the always-on-write intent, while "batch" names the
            // callable boundary. The engine normalizes either spelling via
            // regen_batch().
            foreach (['batch', 'refresh'] as $batchKey) {
                if (!array_key_exists($batchKey, $regen)) {
                    continue;
                }
                $batch = $regen[$batchKey];
                if ($batch !== true && $batch !== false && !is_array($batch)) {
                    throw new \RuntimeException(
                        "wprism: manifest '$name' post_types.$postType.regen_dependency.$batchKey must be "
                        . 'a boolean or object'
                    );
                }
                if (is_array($batch)) {
                    $unknownBatch = array_diff(array_keys($batch), ['enabled', 'always_on_write']);
                    if ($unknownBatch) {
                        throw new \RuntimeException(
                            "wprism: manifest '$name' post_types.$postType.regen_dependency.$batchKey contains unknown key(s): "
                            . implode(', ', $unknownBatch)
                        );
                    }
                    foreach (['enabled', 'always_on_write'] as $flag) {
                        if (array_key_exists($flag, $batch) && !is_bool($batch[$flag])) {
                            throw new \RuntimeException(
                                "wprism: manifest '$name' post_types.$postType.regen_dependency.$batchKey.$flag must be boolean"
                            );
                        }
                    }
                }
            }
            if (array_key_exists('always_on_write', $regen) && !is_bool($regen['always_on_write'])) {
                throw new \RuntimeException(
                    "wprism: manifest '$name' post_types.$postType.regen_dependency.always_on_write must be boolean"
                );
            }
        }
    }

    /**
     * The closed `post_types.<type>.body` vocabulary, ungated.
     *
     * These three are legal for every manifest at every accepted spec_version,
     * and this list is deliberately NOT widened by WP-6.5: a fourth member
     * added here would be legal for a `spec_version: 2` manifest too, which is
     * the flag day § v3.2's channel exists to avoid. `json` is admitted
     * per-manifest instead, by the feature that claims it — see
     * featureGatedBodyModes() and admitted_body_modes() below.
     */
    private const BODY_MODES = ['blocks', 'verbatim', 'serialized'];

    /**
     * The `body` values a DECLARED ENGINE FEATURE admits, feature => modes.
     *
     * `json` (WP-6.5, spec/repo-format.md § v3.20) is a body mode rather than a
     * new top-level switch because the question it answers — how is this post
     * type's body read — is exactly the question the three above answer, and a
     * second switch beside them would let two declarations disagree. The price
     * is that a value inside a closed vocabulary cannot be staged the way a
     * top-level key can, which is why the feature ALSO claims the top-level
     * `body_refs` key: an engine that lacks the feature meets the paths as an
     * unrecognised top-level key and refuses BY FEATURE NAME (§ v3.3's growth
     * rule), instead of meeting `json` alone and refusing it as a typo.
     */
    private const FEATURE_GATED_BODY_MODES = [BodyRefGrammar::FEATURE => [BodyRefGrammar::BODY_MODE]];

    /**
     * feature => the predicate that answers "did THIS manifest declare it".
     *
     * Asked of the feature's own collaborator rather than by reading
     * `engine_features` here, so the file that owns the name owns the read too.
     * Two spellings of one declaration check would agree until one of them
     * learned about, say, a `/v2` successor — and `regress_spec_v3_dry_run.php`
     * ratchets the census of shipped files that consult the channel precisely so
     * an incidental third reader has to be argued for.
     *
     * @return array<string,callable(array<string,mixed>):bool>
     */
    private static function gated_body_mode_predicates(): array {
        return [BodyRefGrammar::FEATURE => static fn(array $m): bool => BodyRefGrammar::declares_feature($m)];
    }

    /** The closed `post_types.<type>.phase` vocabulary. */
    private const POST_TYPE_PHASES = ['normal', 'early'];

    /** @return list<string> Policy::closed_vocabularies()'s body vocabulary. */
    public static function bodyModes(): array {
        return self::BODY_MODES;
    }

    /**
     * The gated body modes, keyed by the engine feature that admits each.
     *
     * Published beside `post_type_body_modes` rather than folded into it, for
     * the reason the constant above states: they are not the same set for the
     * same reader. A manifest declaring no feature may write the three; one
     * declaring `structured-body-refs/v1` may write four. Publishing the union
     * would tell an editor to offer `json` to every author, which is precisely
     * the mis-read the gate exists to convert into a named refusal.
     *
     * @return array<string,list<string>>
     */
    public static function featureGatedBodyModes(): array {
        return self::FEATURE_GATED_BODY_MODES;
    }

    /**
     * The `body` values ONE manifest may declare: the base three, plus the
     * modes its own declared engine features admit.
     *
     * Answered from THIS manifest's own declaration and nothing else, exactly
     * as `ManifestGrammar::assert_invalidate_feature_gate()` answers its own:
     * the declaring document is in hand here (validate_post_type_contracts() is
     * called once per manifest from ManifestValidator and from nowhere else),
     * and a gate that consulted the loaded pin set instead would let one
     * adapter's feature declaration widen another adapter's vocabulary.
     *
     * @param array<string,mixed> $manifest
     * @return list<string>
     */
    private static function admitted_body_modes(array $manifest): array {
        $modes = self::BODY_MODES;
        foreach (self::gated_body_mode_predicates() as $feature => $declares) {
            if ($declares($manifest)) {
                $modes = array_merge($modes, self::FEATURE_GATED_BODY_MODES[$feature]);
            }
        }

        return $modes;
    }

    /** @return list<string> Policy::closed_vocabularies()'s phase vocabulary. */
    public static function postTypePhases(): array {
        return self::POST_TYPE_PHASES;
    }

    public static function defaultBodyMode(): string {
        return self::BODY_MODES[0];
    }

    public static function defaultPostTypePhase(): string {
        return self::POST_TYPE_PHASES[0];
    }

    /**
     * Validate the closed body/phase switches in one manifest.
     *
     * The `body` legal set is now per-manifest (admitted_body_modes()), and the
     * existing refusal is byte for byte what it always was for every manifest
     * that declares no engine feature — which is all 16 shipped adapters, so no
     * manifest byte and no adapter digest moves (AGENTS.md rule 2). A manifest
     * that DOES declare `structured-body-refs/v1` sees `json` listed in the same
     * sentence, because the vocabulary a refusal prints has to be the vocabulary
     * that refused.
     *
     * A GATED value declared WITHOUT its feature is RECOGNISED here and refused
     * later, by `BodyRefGrammar::assert_body_mode_gate()`, and the deferral is
     * the contract rather than an omission. This validator runs early — before
     * `AdapterContractGrammar::validate_adapter_contract()` — and § v3.2/§ v3.3
     * require their three verdicts to arrive FIRST and stay distinct: a
     * `spec_version: 2` manifest must be refused BY SECTION, a v3 one without
     * the feature BY KEY, and an engine lacking the feature BY FEATURE NAME.
     * Refusing `body: "json"` here would pre-empt all three with a fourth
     * sentence about a body mode, telling an author about a feature when the
     * real problem is the spec_version their whole manifest declares. So the
     * gated member is not treated as a typo here, and the ONE case the later
     * validator is left to answer is the case no top-level key can express:
     * the mode declared with no `body_refs` section at all.
     *
     * What is NOT deferred is the printed vocabulary: a value that is neither
     * legal nor gated still refuses here, and the list it prints is the list
     * that refused for THIS manifest.
     */
    public static function validate_post_type_contracts(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        $bodyModes = self::admitted_body_modes($manifest);
        $gated = array_merge(...array_values(self::FEATURE_GATED_BODY_MODES));
        foreach ($manifest['post_types'] ?? [] as $postType => $decl) {
            if (!is_array($decl)) {
                continue; // shape already refused by validate_scope_classes()
            }
            foreach ([['body', $bodyModes], ['phase', self::POST_TYPE_PHASES]] as [$key, $legal]) {
                if (!array_key_exists($key, $decl)) {
                    continue;
                }
                if (in_array($decl[$key], $legal, true)) {
                    continue;
                }
                if ($key === 'body' && in_array($decl[$key], $gated, true)) {
                    continue; // recognised; the feature gate answers for it later
                }
                throw new \RuntimeException(
                    "wprism: manifest '$name' declares post_types.$postType.$key="
                    . var_export($decl[$key], true) . ' but the vocabulary is closed ('
                    . implode(', ', $legal) . ') — it is engine-owned, because each value names engine '
                    . 'behavior the engine implements; a new one is an engine change with a spec bump, not a '
                    . 'manifest declaration'
                );
            }
        }
    }
}
