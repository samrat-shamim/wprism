<?php
namespace Duo;

// This collaborator is loaded by ManifestValidator at load time and by the
// typed-table capture/apply boundaries at runtime, both of which may reach it
// without Policy present. Close its own two dependencies explicitly, exactly
// as its 243 siblings do (AGENTS.md rule 1).
require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Kernel/Secrets.php';

/**
 * `column_codecs` — structured typed-table column codecs (WP-6.1).
 *
 * WHAT WAS MISSING, MEASURED. `tools/engine-gaps.json` records the demand as
 * the primitive `serialized_column_codec`, blocked candidate Redirection 5.9.0,
 * coordinate `tables.redirection_items.columns.action_data`: that column holds
 * a PHP-serialized array containing a target URL, and an authored typed-table
 * column reaches capture through `TypedTableCapture`'s
 * `$tokens->tokenize_text($value)` — the RAW STORAGE BYTES. Substituting
 * `https://source.example/go` with `{{site_url}}/go` inside those bytes leaves
 * every enclosing `s:<n>:` length prefix stating the OLD byte count, so the
 * value captured into canonical state no longer unserializes. That is a silent
 * corruption in the capture path, which DESIGN.md ranks as the worst outcome
 * this engine can produce.
 *
 * The mechanism already existed one surface over and could not be reached from
 * here: an attached-meta rule may declare `plain_data: true`, and
 * `TypedTableCapture::capture_meta_rows()` (:275-283) decodes, rewrites only
 * native string leaves, and re-encodes for exactly this reason — its own
 * comment says so. `tables.<t>.columns.<c>` rules are validated by nothing in
 * `ReferenceShapeGrammar` (it enumerates options/meta/patterns and attached-meta
 * `keys` only), so `plain_data` written on a COLUMN would have loaded and meant
 * nothing. This section is that capability made declarable, refusable, and
 * staged.
 *
 * WHY A TOP-LEVEL SECTION AND NOT A FIELD ON THE COLUMN RULE. Because a field
 * nested inside `tables` CANNOT be staged. spec/repo-format.md § v3.2's channel
 * admits TOP-LEVEL keys: an engine that lacks the feature refuses the adapter by
 * feature name, which is the whole point. A `columns.<c>.codec` field would be
 * silently ignored by every engine that predates it — the exact failure
 * "Vocabulary ownership and extension" describes, where an unrecognised
 * declaration that means nothing is indistinguishable from a deliberate one, and
 * the plugin's authored bytes go to canonical state raw. So the codec is
 * declared BESIDE the table (`columns` says WHAT is authored; `column_codecs`
 * says HOW those bytes decode) and claimed by the engine feature
 * `typed-column-codecs/v1` at `spec_version` 3, which buys all three of § v3.2's
 * distinct verdicts for free:
 *   - a `spec_version: 2` manifest declaring the section is refused BY SECTION
 *     ("this engine implements only at spec_version 3");
 *   - a `spec_version: 3` manifest declaring the section without the feature is
 *     refused BY KEY through the closed top-level key set (§ v3.3);
 *   - an engine without the feature meeting a manifest that declares it refuses
 *     BY FEATURE NAME.
 * No shipped manifest declares either, so no adapter digest moves (rule 2).
 *
 * THE IDENTITY ROUND-TRIP PRECONDITION, which is the load-bearing rule here.
 * Before any substitution, the codec re-encodes what it decoded and requires the
 * result to equal the input bytes EXACTLY. A value that does not survive that
 * is refused rather than rewritten, so a mis-decode is a refusal and never a
 * corruption. It is checked BEFORE the leaf rewrite and not after, deliberately:
 * "after" would mean the engine had already produced a substituted document it
 * would then have to decide what to do with, and the honest answer at that point
 * is unavailable — the original bytes are the only thing that proves the decode
 * was faithful.
 */
final class ColumnCodecGrammar {
    /**
     * The closed `container` vocabulary: how the column's bytes are framed.
     *
     * One value, because one storage framing has measured demand. It is
     * engine-owned for the reason ManifestGrammar::TABLE_CLASSES gives: only
     * the engine can act on a container, so a new one is an engine change with
     * its own feature name, never a manifest declaration.
     */
    private const CONTAINERS = ['php_serialized'];

    /**
     * The closed `leaves` vocabulary: what the codec does to the decoded
     * string leaves once the container is open.
     *
     * `text` is the ordinary environment URL/query-reference pass —
     * `Tokens::plain_data_capture()`/`plain_data_apply()`, the same codec the
     * `plain_data` rule already runs for meta, options and serialized post
     * bodies. Declaring it rather than implying it is what keeps a later
     * second leaf treatment from silently changing what an existing
     * declaration means.
     */
    private const LEAVES = ['text'];

    /** @return list<string> Policy::closed_vocabularies()'s read of CONTAINERS. */
    public static function containers(): array {
        return self::CONTAINERS;
    }

    /** @return list<string> Policy::closed_vocabularies()'s read of LEAVES. */
    public static function leafCodecs(): array {
        return self::LEAVES;
    }

    /**
     * The top-level section `typed-column-codecs/v1` claims, and one codec's
     * closed key set — both hoisted out of literals for WP-6.6, so
     * `--emit-schema` publishes the shape from the constants the refusals below
     * are written against rather than from a copy.
     */
    public const SECTION = 'column_codecs';
    public const CODEC_KEYS = ['container', 'leaves'];

    /**
     * This section's value grammar, published for `duo manifest-validate
     * --emit-schema` (WP-6.6, spec/repo-format.md § v3.21).
     *
     * `refines` carries the half a shape cannot: a codec may address only an
     * `authored` column of an `authored_snapshot` table, and never the
     * `slug_column` or an identity component — three refusals whose reasons are
     * about derivation stability rather than about syntax, and which an author
     * otherwise meets one round trip at a time.
     *
     * @return array<string,mixed>
     */
    public static function section_grammar(): array {
        return [
            'keyed_by' => 'unprefixed table name, then column name — the table must be one THIS manifest '
                . 'declares as class=authored_snapshot, and the column one of its declared authored columns{}',
            'codec' => ['required' => self::CODEC_KEYS, 'optional' => []],
            'container' => self::CONTAINERS,
            'leaves' => self::LEAVES,
            'refines' => 'never the table\'s slug_column and never an identity column (identity.column, '
                . 'identity.columns[] or a composite_ref tuple): a decoded container has no filename '
                . 'spelling, and a derived uuid may not depend on this engine\'s serializer',
            'validated_by' => 'Duo\\ColumnCodecGrammar::validate_column_codecs()',
        ];
    }

    /**
     * Loud, load-time guard for one manifest's `column_codecs` section.
     *
     * Every cross-check below names a way the declaration could be accepted and
     * then do nothing, which is the failure mode this whole section exists to
     * remove:
     *   - a codec for a table this manifest does not declare, or declares as
     *     anything but `authored_snapshot`, addresses no captured column;
     *   - a codec for a column that is not a declared `columns{}` entry, or is
     *     not `authored`, names bytes capture never reads;
     *   - a codec on the `slug_column` or on a `natural_key` identity component
     *     would put a decoded container where the engine needs a stable scalar
     *     (a filename half, or the input to a derived uuid).
     * A codec's table is checked against THIS manifest rather than the loaded
     * pin set on purpose: the column belongs to one adapter's own table
     * declaration, and a cross-manifest codec would let a stranger's manifest
     * change how another adapter's bytes decode.
     *
     * @param array<string,mixed> $manifest
     */
    public static function validate_column_codecs(array $manifest, string $label): void {
        if (!array_key_exists(self::SECTION, $manifest)) {
            return;
        }
        $section = $manifest[self::SECTION];
        if (!is_array($section) || array_is_list($section) || $section === []) {
            throw new \RuntimeException(
                "duo: $label column_codecs must be a non-empty object keyed by unprefixed table name, each value an "
                . 'object keyed by column name — an empty section declares a capability the adapter does not use'
            );
        }
        $tables = is_array($manifest['tables'] ?? null) ? $manifest['tables'] : [];
        foreach ($section as $table => $columns) {
            $table = (string) $table;
            $where = "$label column_codecs.$table";
            if (!is_array($columns) || array_is_list($columns) || $columns === []) {
                throw new \RuntimeException(
                    "duo: $where must be a non-empty object keyed by column name, each value a codec declaration"
                );
            }
            $decl = $tables[$table] ?? null;
            if (!is_array($decl) || ($decl['class'] ?? null) !== 'authored_snapshot') {
                throw new \RuntimeException(
                    "duo: $where names a table this manifest does not declare as class=authored_snapshot — a column "
                    . 'codec addresses one of this adapter\'s own captured row tables, so a codec for a table '
                    . 'declared elsewhere (or not at all) would decode bytes nothing here reads'
                );
            }
            // Every column the declaration names as identity, in EITHER
            // spelling and in every mode — deliberately broader than
            // ManifestGrammar::natural_key_columns(), which is mode-gated and
            // order-preserving because a derivation reads it. The question here
            // is only "does identity depend on this column's own value", and it
            // does for `identity.column`, for `identity.columns[]`, and for a
            // composite_ref tuple alike. Asking it in this file's own terms is
            // also what keeps the Grammar module from taking a new edge into
            // Policy for six lines of array reading.
            $identity = is_array($decl['identity'] ?? null) ? $decl['identity'] : [];
            $identityColumns = [];
            if (array_key_exists('column', $identity)) {
                $identityColumns[] = (string) $identity['column'];
            }
            foreach ((array) ($identity['columns'] ?? []) as $identityColumn) {
                $identityColumns[] = (string) $identityColumn;
            }
            $slugColumn = is_string($decl['slug_column'] ?? null) ? $decl['slug_column'] : null;
            foreach ($columns as $column => $codec) {
                self::validate_one(
                    (string) $column,
                    $codec,
                    "$where." . (string) $column,
                    is_array($decl['columns'] ?? null) ? $decl['columns'] : [],
                    $identityColumns,
                    $slugColumn
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $columnRules
     * @param list<string> $identityColumns
     */
    private static function validate_one(
        string $column,
        mixed $codec,
        string $where,
        array $columnRules,
        array $identityColumns,
        ?string $slugColumn
    ): void {
        $rule = $columnRules[$column] ?? null;
        if (!is_array($rule) || ($rule['class'] ?? null) !== 'authored') {
            throw new \RuntimeException(
                "duo: $where names a column that is not a declared authored columns{} entry — a codec decodes "
                . 'CAPTURED bytes, and a ref, runtime, derived or env column is never carried into canonical state, '
                . 'so the declaration would have nothing to act on'
            );
        }
        if ($slugColumn === $column) {
            throw new \RuntimeException(
                "duo: $where names this table's slug_column — a slug is the human-readable half of a canonical "
                . 'filename and must stay a plain scalar; a decoded container has no filename spelling'
            );
        }
        if (in_array($column, $identityColumns, true)) {
            throw new \RuntimeException(
                "duo: $where names an identity column — identity is derived from the column's own value, so "
                . 'decoding and re-encoding it would make the derived uuid depend on this engine\'s serializer '
                . 'rather than on the authored fact'
            );
        }
        if (!is_array($codec) || array_is_list($codec)) {
            throw new \RuntimeException(
                "duo: $where must be an object declaring exactly {container, leaves}"
            );
        }
        $keys = array_keys($codec);
        sort($keys, SORT_STRING);
        if ($keys !== self::CODEC_KEYS) {
            throw new \RuntimeException(
                "duo: $where declares [" . implode(', ', array_map('strval', $keys)) . '] but a column codec is '
                . 'exactly {container, leaves} — both are required because a codec with an implied container is a '
                . 'declaration whose meaning changes the next time the engine grows one'
            );
        }
        if (!in_array($codec['container'], self::CONTAINERS, true)) {
            throw new \RuntimeException(
                "duo: $where declares container=" . var_export($codec['container'], true)
                . ' but the column container vocabulary is closed and engine-owned ('
                . implode(', ', self::CONTAINERS) . ') — only the engine can decode a container, so a new one is '
                . 'an engine change with its own engine feature, not a manifest declaration'
            );
        }
        if (!in_array($codec['leaves'], self::LEAVES, true)) {
            throw new \RuntimeException(
                "duo: $where declares leaves=" . var_export($codec['leaves'], true)
                . ' but the leaf codec vocabulary is closed and engine-owned (' . implode(', ', self::LEAVES)
                . ') — "text" is the ordinary home/uploads URL and query-reference pass'
            );
        }
    }

    /**
     * The declared codecs for ONE table, merged across a pin set.
     *
     * Last pinned manifest wins per table, matching
     * ContentAttributeRuleResolver's precedence for block/shortcode attribute
     * registries and for the same reason: how a plugin frames its own column is
     * a structural fact about that plugin, not a site-local policy choice, so
     * there is no `site.duo.json` override and the later pin simply replaces the
     * earlier declaration whole.
     *
     * @param list<array<string,mixed>> $manifests
     * @return array<string,array{container:string,leaves:string}> column => codec
     */
    public static function rules_for(array $manifests, string $table): array {
        $out = [];
        foreach ($manifests as $manifest) {
            $declared = $manifest['column_codecs'][$table] ?? null;
            if (is_array($declared) && $declared !== []) {
                $out = $declared;
            }
        }
        return $out;
    }

    /**
     * Capture direction: storage bytes -> canonical bytes.
     *
     * Returns the re-encoded container with its string leaves tokenized and
     * every length prefix recomputed by PHP's own serializer, which is the
     * half `tokenize_text()` on raw bytes could never get right.
     *
     * @param array{container:string,leaves:string} $codec
     */
    public static function capture_value(mixed $raw, array $codec, object $tokens, string $where): string {
        $decoded = self::decode($raw, $codec, $where, 'captured');
        // The secret gate runs on the DECODED value, matching the attached-meta
        // plain_data path (TypedTableCapture.php:282): a credential nested three
        // levels inside a serialized container is the case Secrets::
        // hard_match_deep() exists for, and screening the raw framing bytes
        // instead would only ever see the outer string.
        $label = Secrets::hard_match_deep($decoded);
        if ($label !== null) {
            throw new \RuntimeException(
                "duo: secret guard tripped — $where decodes to a value containing a $label but is classified "
                . "authored; refusing to capture it into state/.\n"
                . 'If this is really a secret, reclassify the column runtime/derived/env instead of authored.'
            );
        }
        return serialize($tokens->plain_data_capture($decoded));
    }

    /**
     * Apply direction: canonical bytes -> storage bytes.
     *
     * @param array{container:string,leaves:string} $codec
     */
    public static function apply_value(mixed $canonical, array $codec, object $tokens, string $where): string {
        $decoded = self::decode($canonical, $codec, $where, 'authored');
        return serialize($tokens->plain_data_apply($decoded));
    }

    /**
     * Open the container, and PROVE the decode was faithful before anything is
     * substituted.
     *
     * `PlainData::decode()` already refuses objects, PHP references, recursion,
     * excessive depth and trailing bytes, so what remains for this method is the
     * one property a codec owes its caller: that re-encoding what it decoded
     * reproduces the input EXACTLY. When it does not, the engine cannot tell a
     * harmless serializer spelling from a value it has mis-read, and the only
     * answer that cannot corrupt authored data is to refuse — before the
     * substitution, not after it.
     *
     * A decode to a non-container is refused for a different reason: a scalar
     * column needs no codec (the ordinary `tokenize_text()` path already handles
     * it correctly), so a codec declared over one is a declaration whose author
     * believes something about the data that is not true.
     *
     * @param array{container:string,leaves:string} $codec
     * @return array<mixed>
     */
    private static function decode(mixed $bytes, array $codec, string $where, string $side): array {
        $container = (string) $codec['container'];
        if (!is_string($bytes)) {
            // Refused rather than coerced. A codec is a claim about how a
            // column's BYTES are framed, and a column holding an integer or a
            // NULL on this environment is a fact about the declaration being
            // wrong here, not a value to cast into shape.
            throw new \RuntimeException(
                "duo: $where declares the '$container' column codec but the $side value is "
                . get_debug_type($bytes) . ', not a string — a container codec decodes stored bytes'
            );
        }
        $decoded = PlainData::decode($bytes, $where);
        $reencoded = is_object($decoded) ? null : @serialize($decoded);
        if ($reencoded !== $bytes) {
            throw new \RuntimeException(
                "duo: $where declares the '$container' column codec, but re-encoding the $side value does not "
                . 'reproduce its bytes exactly, so the decode cannot be trusted — refusing BEFORE any substitution '
                . 'rather than writing back a container this engine may have mis-read. Either the value is not '
                . "canonical $container data, or the column is misdeclared"
            );
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException(
                "duo: $where declares the '$container' column codec but the $side value decodes to "
                . get_debug_type($decoded) . ' — a column codec exists to reach the string leaves INSIDE a '
                . 'container; a scalar column is already tokenized correctly without one'
            );
        }
        return $decoded;
    }
}
