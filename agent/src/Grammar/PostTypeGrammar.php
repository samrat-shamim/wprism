<?php
namespace Duo;

/**
 * The pure post-type behavior grammar extracted from Policy.php
 * (DUO-3348): the closed `post_types.<type>.body` and `.phase` switches and
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
                    "duo: manifest '$name' post_types key " . var_export($parentPostType, true)
                    . ' cannot declare children: expected a WordPress post-type name'
                );
            }
            $children = $decl['children'];
            if (!is_array($children) || !array_is_list($children) || !$children) {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types.$parentPostType.children must be a non-empty list"
                );
            }
            $seen = [];
            foreach ($children as $index => $childPostType) {
                if (!is_string($childPostType)
                    || !preg_match('/^[a-z0-9_-]{1,20}$/', $childPostType)) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' post_types.$parentPostType.children[$index] "
                        . 'must be a WordPress post-type name'
                    );
                }
                if ($childPostType === $parentPostType) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' post_types.$parentPostType.children "
                        . 'cannot declare a CPT as its own child'
                    );
                }
                if (!array_key_exists($childPostType, $postTypes)) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' post_types.$parentPostType.children[$index] "
                        . "names undeclared child CPT '$childPostType'"
                    );
                }
                if (isset($seen[$childPostType])) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' post_types.$parentPostType.children "
                        . "contains duplicate child CPT '$childPostType'"
                    );
                }
                $seen[$childPostType] = true;
            }
        }
    }

    /**
     * Validate `post_types.<type>.regen_dependency` shape at load time
     * (DUO-3234). This checks only declaration shape: required keys, scalar
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
                    "duo: manifest '$name' post_types.$postType.regen_dependency must be an object"
                );
            }
            $regenerator = $regen['regenerator'] ?? null;
            if (!is_string($regenerator) || $regenerator === '') {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types.$postType.regen_dependency needs a non-empty string 'regenerator'"
                );
            }
            $verify = $regen['verify'] ?? null;
            if (!is_array($verify) || !is_string($verify['table'] ?? null) || ($verify['table'] ?? '') === ''
                || !is_string($verify['column'] ?? null) || ($verify['column'] ?? '') === '') {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types.$postType.regen_dependency needs "
                    . 'verify: {table: <non-empty string>, column: <non-empty string>}'
                );
            }

            $unknown = array_diff(
                array_keys($regen),
                ['regenerator', 'verify', 'batch', 'refresh', 'always_on_write', 'effects']
            );
            if ($unknown) {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types.$postType.regen_dependency contains unknown key(s): "
                    . implode(', ', $unknown)
                );
            }
            if (array_key_exists('batch', $regen) && array_key_exists('refresh', $regen)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types.$postType.regen_dependency cannot declare both 'batch' and 'refresh'"
                );
            }
            if (array_key_exists('always_on_write', $regen)
                && (array_key_exists('batch', $regen) || array_key_exists('refresh', $regen))) {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types.$postType.regen_dependency.always_on_write is ambiguous beside batch/refresh"
                );
            }

            // DUO-329x: batch/refresh is deliberately opt-in. Existing
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
                        "duo: manifest '$name' post_types.$postType.regen_dependency.$batchKey must be "
                        . 'a boolean or object'
                    );
                }
                if (is_array($batch)) {
                    $unknownBatch = array_diff(array_keys($batch), ['enabled', 'always_on_write']);
                    if ($unknownBatch) {
                        throw new \RuntimeException(
                            "duo: manifest '$name' post_types.$postType.regen_dependency.$batchKey contains unknown key(s): "
                            . implode(', ', $unknownBatch)
                        );
                    }
                    foreach (['enabled', 'always_on_write'] as $flag) {
                        if (array_key_exists($flag, $batch) && !is_bool($batch[$flag])) {
                            throw new \RuntimeException(
                                "duo: manifest '$name' post_types.$postType.regen_dependency.$batchKey.$flag must be boolean"
                            );
                        }
                    }
                }
            }
            if (array_key_exists('always_on_write', $regen) && !is_bool($regen['always_on_write'])) {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types.$postType.regen_dependency.always_on_write must be boolean"
                );
            }
        }
    }

    /** The closed `post_types.<type>.body` vocabulary. */
    private const BODY_MODES = ['blocks', 'verbatim', 'serialized'];

    /** The closed `post_types.<type>.phase` vocabulary. */
    private const POST_TYPE_PHASES = ['normal', 'early'];

    /** @return list<string> Policy::closed_vocabularies()'s body vocabulary. */
    public static function bodyModes(): array {
        return self::BODY_MODES;
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

    /** Validate the closed body/phase switches in one manifest. */
    public static function validate_post_type_contracts(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ($manifest['post_types'] ?? [] as $postType => $decl) {
            if (!is_array($decl)) {
                continue; // shape already refused by validate_scope_classes()
            }
            foreach ([['body', self::BODY_MODES], ['phase', self::POST_TYPE_PHASES]] as [$key, $legal]) {
                if (!array_key_exists($key, $decl)) {
                    continue;
                }
                if (!in_array($decl[$key], $legal, true)) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' declares post_types.$postType.$key="
                        . var_export($decl[$key], true) . ' but the vocabulary is closed ('
                        . implode(', ', $legal) . ') — it is engine-owned, because each value names engine '
                        . 'behavior the engine implements; a new one is an engine change with a spec bump, not a '
                        . 'manifest declaration'
                    );
                }
            }
        }
    }
}
