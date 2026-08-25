<?php
namespace Duo;

/**
 * `attr_id_codecs` — the type-preserving block attribute id codec (WP-6.1).
 *
 * WHAT WAS MISSING, MEASURED. `tools/engine-gaps.json` records the demand as
 * the primitive `type_preserving_string_id_attr_codec`, blocked candidate
 * WPForms Lite 2.0.0.4/2.0.0.5, coordinate
 * `block_attrs.wpforms/form-selector`: that block persists `formId` as a JSON
 * STRING. The shipped codec's value vocabulary is closed at `{int, int[]}`
 * (`AttributeGrammar::ATTR_VALUE_TYPES`) and its apply arm writes
 * `(int) $v`/`(int) $t` unconditionally (`Blocks.php:206`, `:212`), so a block
 * whose id is stored as a string comes back from a round trip as
 * `"formId":12` where the plugin wrote `"formId":"12"`. The identity resolves;
 * the STORED TYPE does not survive. `AttributeGrammar`'s own refusal already
 * says what to do about that — "any other shape needs engine support before it
 * can be declared" — and this section is that engine support.
 *
 * WHY THE TYPE IS DECLARED AND NOT OBSERVED. Preserving whatever type the
 * SOURCE happened to hold would make the canonical document carry an
 * environment's incidental spelling, and would say nothing at all about a
 * target where the attribute is absent and is being written for the first time.
 * How a plugin stores its own id is a fact about the plugin, so it belongs in
 * the plugin's manifest, exactly where `block_attrs` already lives.
 *
 * WHY A TOP-LEVEL SECTION RATHER THAN A `type: "string"` VALUE. Because a value
 * added to a nested vocabulary cannot be staged. spec/repo-format.md § v3.2's
 * channel admits TOP-LEVEL keys, so an engine that lacks the feature refuses
 * the adapter BY NAME; an engine that predates a new `type` value would refuse
 * it as an unknown vocabulary member — better than silence, but it refuses a
 * manifest it could otherwise have loaded, and it cannot tell the author which
 * engine feature to look for. Declaring the codec beside `block_attrs` and
 * claiming it with the engine feature `attr-id-codecs/v1` at `spec_version` 3
 * buys § v3.2's three distinct verdicts (section-by-name at v2, key-by-name at
 * v3 without the feature, feature-by-name on an engine that lacks it) and
 * leaves `block_attrs`' own closed vocabulary exactly as it is — so no shipped
 * manifest's bytes move and no adapter digest moves (AGENTS.md rule 2).
 *
 * THE IDENTITY ROUND-TRIP PRECONDITION. A declaration that the plugin stores a
 * string is checked against the SOURCE at capture: if the live attribute is not
 * a string, the codec would rewrite `"formId":12` into `"formId":"12"` on a
 * target where nothing was substituted — a byte change the author did not ask
 * for, produced by a declaration that is false about this block. Capture
 * refuses it, before the value is tokenized, for the same reason
 * ColumnCodecGrammar refuses a container it cannot re-encode: a codec that
 * cannot round-trip an untouched value is mis-declared, and the only answer
 * that cannot corrupt authored content is to say so.
 */
final class AttrIdCodecGrammar {
    /**
     * The top-level section `attr-id-codecs/v1` claims.
     *
     * Named here for the reason `BodyRefGrammar::SECTION` gives for its own:
     * `AdapterContractGrammar`'s roster, this validator and the published
     * `--emit-schema` grammar all have to be talking about the same string, and
     * three literals agree until one of them moves. The refusal MESSAGES below
     * keep their literal spelling deliberately — they are pinned prose an
     * author reads, not a lookup.
     */
    public const SECTION = 'attr_id_codecs';

    /**
     * One codec's closed key set: exactly `{id_type}`, both directions.
     *
     * Hoisted out of validate_one()'s `$keys !== ['id_type']` for WP-6.6, so
     * `--emit-schema` publishes the shape from the constant the refusal is
     * written against rather than from a copy.
     */
    public const CODEC_KEYS = ['id_type'];

    /**
     * The closed `id_type` vocabulary: the JSON type a resolved id is written
     * back as.
     *
     * `string` only, and `int` deliberately absent: integer is what the shipped
     * codec already does for every rule, so admitting it here would be a
     * declaration that means "the default" — a member whose only effect is to
     * make two spellings of one behaviour, which is how a closed vocabulary
     * stops being a decision.
     */
    private const ID_TYPES = ['string'];

    /** @return list<string> Policy::closed_vocabularies()'s read of ID_TYPES. */
    public static function idTypes(): array {
        return self::ID_TYPES;
    }

    /**
     * This section's value grammar, published for `duo manifest-validate
     * --emit-schema` (WP-6.6, spec/repo-format.md § v3.21).
     *
     * Two nested key levels and one closed vocabulary is the whole shape, and
     * all three are read from the constants the refusals above are written
     * against. `refines` is stated because it is the half a shape alone cannot
     * carry: a codec addresses a `block_attrs` rule that must already exist,
     * already resolve an id, and already be scalar — three refusals an author
     * meets in that order (validate_one()).
     *
     * @return array<string,mixed>
     */
    public static function section_grammar(): array {
        return [
            'keyed_by' => 'block name, then attribute path — both must be a `block_attrs` rule THIS manifest '
                . 'declares, because a codec refines a declared rule and never introduces one',
            'codec' => ['required' => self::CODEC_KEYS, 'optional' => []],
            'id_type' => self::ID_TYPES,
            'refines' => 'only a scalar entity-ref rule: a rule carrying tokenize, unsupported, lint_ok or '
                . 'type="int[]" resolves no single id, and a codec over it is refused by name',
            'validated_by' => 'Duo\\AttrIdCodecGrammar::validate_attr_id_codecs()',
        ];
    }

    /**
     * Loud, load-time guard for one manifest's `attr_id_codecs` section.
     *
     * Every cross-check names a way the declaration could load and then do
     * nothing — the failure `AttributeGrammar::validate_attr_rules()` already
     * exists to prevent one level down, where a misspelled `path` is
     * indistinguishable from "this block simply had no such attribute":
     *   - a codec for a block this manifest declares no `block_attrs` for, or
     *     for a path none of that block's rules names, addresses nothing;
     *   - a codec on a rule that is not a scalar entity ref (`tokenize: text`,
     *     `lint_ok`, `unsupported`, or `type: "int[]"`) names a value the id
     *     codec never reaches: the first three are other dispositions entirely,
     *     and a native JSON list of ids is a shape whose per-element type this
     *     primitive has no measured demand for and therefore does not claim.
     * The codec's block is checked against THIS manifest and not the loaded pin
     * set, so one adapter cannot change how another adapter's declared block
     * attribute is written back.
     *
     * @param array<string,mixed> $manifest
     */
    public static function validate_attr_id_codecs(array $manifest, string $label): void {
        if (!array_key_exists(self::SECTION, $manifest)) {
            return;
        }
        $section = $manifest[self::SECTION];
        if (!is_array($section) || array_is_list($section) || $section === []) {
            throw new \RuntimeException(
                "duo: $label attr_id_codecs must be a non-empty object keyed by block name, each value an object "
                . 'keyed by attribute path — an empty section declares a capability the adapter does not use'
            );
        }
        $registry = is_array($manifest['block_attrs'] ?? null) ? $manifest['block_attrs'] : [];
        foreach ($section as $block => $paths) {
            $block = (string) $block;
            $where = "$label attr_id_codecs.$block";
            if (!is_array($paths) || array_is_list($paths) || $paths === []) {
                throw new \RuntimeException(
                    "duo: $where must be a non-empty object keyed by attribute path, each value a codec declaration"
                );
            }
            $rules = $registry[$block] ?? null;
            if (!is_array($rules) || !array_is_list($rules) || $rules === []) {
                throw new \RuntimeException(
                    "duo: $where names a block this manifest declares no block_attrs rules for — an id codec "
                    . 'refines a declared attribute rule, so a codec with no rule beneath it would never run'
                );
            }
            foreach ($paths as $path => $codec) {
                self::validate_one((string) $path, $codec, "$where." . (string) $path, $rules);
            }
        }
    }

    /** @param list<array<string,mixed>> $rules */
    private static function validate_one(string $path, mixed $codec, string $where, array $rules): void {
        $rule = null;
        foreach ($rules as $candidate) {
            if (is_array($candidate) && ($candidate['path'] ?? null) === $path) {
                $rule = $candidate;
                break;
            }
        }
        if ($rule === null) {
            throw new \RuntimeException(
                "duo: $where names an attribute path none of this block's block_attrs rules declares — a codec "
                . 'over an undeclared path is silently skipped at rewrite time, which is exactly the '
                . 'declared-but-never-applied failure the attribute grammar refuses one level down'
            );
        }
        $blocking = array_keys(array_filter([
            'tokenize' => array_key_exists('tokenize', $rule),
            'unsupported' => array_key_exists('unsupported', $rule),
            'lint_ok' => !empty($rule['lint_ok']),
        ]));
        if ($blocking !== []) {
            throw new \RuntimeException(
                "duo: $where refines a rule whose disposition is '" . implode("', '", $blocking)
                . "' rather than an entity ref — an id codec decides the JSON type a RESOLVED ID is written back "
                . 'as, and that rule resolves no id'
            );
        }
        if (!array_key_exists('kind', $rule) && !array_key_exists('kind_from', $rule)) {
            throw new \RuntimeException(
                "duo: $where refines a rule that declares neither kind nor kind_from — an id codec decides the "
                . 'JSON type a RESOLVED ID is written back as, and that rule resolves no id'
            );
        }
        if (($rule['type'] ?? 'int') !== 'int') {
            throw new \RuntimeException(
                "duo: $where refines a rule declaring type=" . var_export($rule['type'], true)
                . ' — an id codec applies to a scalar id attribute only. A native JSON list of ids has a '
                . 'per-element stored type this engine has no measured demand for and therefore does not claim'
            );
        }
        if (!is_array($codec) || array_is_list($codec)) {
            throw new \RuntimeException("duo: $where must be an object declaring exactly {id_type}");
        }
        $keys = array_keys($codec);
        sort($keys, SORT_STRING);
        if ($keys !== self::CODEC_KEYS) {
            throw new \RuntimeException(
                "duo: $where declares [" . implode(', ', array_map('strval', $keys))
                . '] but an id codec is exactly {id_type}'
            );
        }
        if (!in_array($codec['id_type'], self::ID_TYPES, true)) {
            throw new \RuntimeException(
                "duo: $where declares id_type=" . var_export($codec['id_type'], true)
                . ' but the stored-id type vocabulary is closed and engine-owned ('
                . implode(', ', self::ID_TYPES) . ') — an integer id is what every rule already writes back, so '
                . 'there is no declaration for it to make'
            );
        }
    }

    /**
     * blockName => attribute path => codec, merged across a pin set.
     *
     * Last pinned manifest wins per BLOCK, matching
     * ContentAttributeRuleResolver::block_attr_rules()'s precedence exactly:
     * the rule list and the codecs over it are one declaration about one
     * block's attribute grammar, so they must be replaced together or a later
     * pin's rules would be read through an earlier pin's codecs.
     *
     * @param list<array<string,mixed>> $manifests
     * @return array<string,array<string,array{id_type:string}>>
     */
    public static function rules(array $manifests): array {
        $out = [];
        foreach ($manifests as $manifest) {
            // Keyed off `block_attrs` and not off `attr_id_codecs`, which is
            // what makes replacement WHOLE: a later pin that redeclares the
            // block's rules without codecs must drop the earlier pin's codecs
            // too, or the new rule list would be read through the old one's.
            // Validation guarantees a codec's block declares rules in the same
            // manifest, so nothing is lost by walking the rules side.
            foreach ($manifest['block_attrs'] ?? [] as $block => $_) {
                $codecs = $manifest['attr_id_codecs'][$block] ?? null;
                if (is_array($codecs) && $codecs !== []) {
                    $out[(string) $block] = $codecs;
                } else {
                    unset($out[(string) $block]);
                }
            }
        }
        return $out;
    }

    /**
     * Write one resolved local id back in its declared stored type.
     *
     * The default arm is `(int)`, byte for byte what `Blocks.php` did before
     * this section existed, so an attribute with no codec is unchanged.
     *
     * @param array<string,array{id_type:string}> $codecs this block's codecs
     */
    public static function encode_id(int $id, array $codecs, string $path): int|string {
        return (($codecs[$path]['id_type'] ?? null) === 'string') ? (string) $id : $id;
    }

    /**
     * The capture-side identity round-trip precondition.
     *
     * Refuses a source value whose type disagrees with the declaration, BEFORE
     * the value is tokenized: apply writes the declared type, so a false
     * declaration would change bytes on a target where nothing was
     * substituted. `0`/`"0"` never reaches here — `Blocks::walk()` treats it as
     * WordPress's own "unset" convention and drops the attribute one step
     * earlier, which is a decision about absence rather than about type.
     *
     * @param array<string,array{id_type:string}> $codecs this block's codecs
     */
    public static function assert_source_type(mixed $value, array $codecs, string $path, string $block): void {
        if (($codecs[$path]['id_type'] ?? null) !== 'string' || is_string($value)) {
            return;
        }
        throw new \RuntimeException(
            "duo: block '$block' attribute '$path' declares the id codec id_type=string, but this source stores it "
            . 'as ' . get_debug_type($value) . ' — apply writes the DECLARED type, so capturing this value would '
            . 'change the block\'s bytes on a target where nothing was substituted. Refusing before substitution: '
            . 'either the codec is declared for the wrong attribute, or this content predates the plugin version '
            . 'the adapter\'s version_range claims'
        );
    }
}
