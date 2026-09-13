<?php
namespace WPrism;

// This collaborator is loaded by ManifestValidator at load time and by the
// typed-table capture/apply boundaries at runtime, both of which may reach it
// without Policy present. Close its own two dependencies explicitly, exactly
// as its 243 siblings do (AGENTS.md rule 1).
require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Kernel/PersonalData.php';
require_once __DIR__ . '/../Kernel/Secrets.php';
require_once __DIR__ . '/../Kernel/StructuredValue.php';
require_once __DIR__ . '/../Kernel/ValueContractGrammar.php';
require_once __DIR__ . '/../Kernel/FieldLabelMap.php';
require_once __DIR__ . '/../Kernel/FieldTemplateMap.php';
require_once __DIR__ . '/../Kernel/InputFileBinding.php';
require_once __DIR__ . '/../Kernel/RecordFields.php';
require_once __DIR__ . '/../Kernel/ColumnValueCases.php';
require_once __DIR__ . '/AuthoredValueCodec.php';

/**
 * `column_codecs` — structured typed-table column codecs (WP-6.1).
 *
 * WHAT WAS MISSING, MEASURED. `tools/engine-gaps.json` records the demand as
 * the primitive `serialized_column_codec`, blocked candidate Redirection 5.9.0,
 * coordinate `tables.redirection_items.columns.action_data`: one native action
 * stores a PHP-serialized array containing target URLs, while other native
 * actions store a plain URL or NULL in the same column. An authored typed-table
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
 * Redirection is the first shipped manifest to declare both codec features;
 * no pre-existing adapter digest moves (rule 2).
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
     * The original strict serialized container and Redirection's explicitly
     * feature-gated serialized-or-text union. They are engine-owned for the
     * reason ManifestGrammar::TABLE_CLASSES gives: only the engine can act on
     * a container, so a new one is an engine change with its own feature name,
     * never a manifest declaration.
     */
    private const CONTAINERS = ['php_serialized', 'php_serialized_or_text', 'json'];

    /**
     * Redirection 5.9.0 measured the first mixed-framing demand: the same
     * `action_data` column stores a plain URL, a serialized conditional map,
     * or NULL according to matcher/action type. The v1 codec correctly refuses
     * either scalar shape, so widening it silently would change an already
     * named feature. This value-vocabulary feature stages the explicit union.
     */
    public const MIXED_FEATURE = 'mixed-column-codecs/v1';

    /** Stored JSON needs decoded URL rewriting and the same faithful-framing proof as serialized columns. */
    public const JSON_FEATURE = 'json-column-codecs/v1';

    public const VALUES_FEATURE = 'typed-column-values/v1';
    public const FIELD_LABELS_FEATURE = 'column-field-labels/v1';
    public const RECORDS_FEATURE = 'column-record-fields/v1';
    public const VALUE_CODEC_KEYS = ['container', 'value'];

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
     * This section's value grammar, published for `wprism manifest-validate
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
            'value_codec' => ['required' => self::VALUE_CODEC_KEYS, 'feature' => self::VALUES_FEATURE,
                'refines' => 'strict json or php_serialized container; authored value contract; every ref declares on_unmapped:refuse; record_fields requires its column feature; no text_encoding'],
            'value_cases' => ['feature' => ColumnValueCases::FEATURE] + ColumnValueCases::declaration_grammar(),
            'input_file' => ['feature' => InputFileBinding::FEATURE, 'shape' => 'authored input_file:{directory,extensions}',
                'refines' => 'ordinary typed rows; content-relative directory, literal filename; canonical dependency marker or empty draft; target-local env-set intent and readable regular file; no file transport'],
            'field_templates' => ['feature' => FieldTemplateMap::FEATURE, 'formats' => FieldTemplateMap::FORMATS,
                'max_expression_bytes' => FieldTemplateMap::MAX_BYTES, 'max_fragments' => FieldTemplateMap::MAX_FRAGMENTS,
                'refines' => 'authored field-code map; native brace expressions or [expression, integer 0 or 1]; canonical text/field fragment lists; only literals use text transport and destination privacy roles'],
            'field_labels' => ['feature' => self::FIELD_LABELS_FEATURE, 'formats' => FieldLabelMap::FORMATS,
                'refines' => 'authored value codec; field-code map to string labels or [string label, integer 0 or 1]; empty map allowed; all key/value bytes still scanned'],
            'record_fields' => ['feature' => self::RECORDS_FEATURE, 'shape' => RecordFields::declaration_grammar()['shape'],
                'max_fields' => RecordFields::MAX_FIELDS,
                'refines' => 'shared immediate record projection at the selected value root; canonical excluded fields refuse; original decoded clearance still applies; no target-local merge'],
            'container' => self::CONTAINERS,
            'leaves' => self::LEAVES,
            'refines' => 'never the table\'s slug_column and never an identity column (identity.column, '
                . 'identity.columns[] or a composite_ref tuple): a decoded container has no filename '
                . 'spelling, and a derived uuid may not depend on this engine\'s serializer',
            'validated_by' => 'WPrism\\ColumnCodecGrammar::validate_column_codecs()',
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
                "wprism: $label column_codecs must be a non-empty object keyed by unprefixed table name, each value an "
                . 'object keyed by column name — an empty section declares a capability the adapter does not use'
            );
        }
        $tables = is_array($manifest['tables'] ?? null) ? $manifest['tables'] : [];
        $values = new ValueContractGrammar(true,
            in_array(self::RECORDS_FEATURE, (array) ($manifest['engine_features'] ?? []), true), false, 'column', true,
            in_array(self::FIELD_LABELS_FEATURE, (array) ($manifest['engine_features'] ?? []), true),
            in_array(FieldTemplateMap::FEATURE, (array) ($manifest['engine_features'] ?? []), true),
            in_array(InputFileBinding::FEATURE, (array) ($manifest['engine_features'] ?? []), true));
        foreach ($section as $table => $columns) {
            $table = (string) $table;
            $where = "$label column_codecs.$table";
            if (!is_array($columns) || array_is_list($columns) || $columns === []) {
                throw new \RuntimeException(
                    "wprism: $where must be a non-empty object keyed by column name, each value a codec declaration"
                );
            }
            $decl = $tables[$table] ?? null;
            if (!is_array($decl) || ($decl['class'] ?? null) !== 'authored_snapshot') {
                throw new \RuntimeException(
                    "wprism: $where names a table this manifest does not declare as class=authored_snapshot — a column "
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
                    $slugColumn,
                    $manifest,
                    $values,
                    $decl
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
        ?string $slugColumn,
        array $manifest,
        ValueContractGrammar $values,
        array $table
    ): void {
        $rule = $columnRules[$column] ?? null;
        if (!is_array($rule) || ($rule['class'] ?? null) !== 'authored') {
            throw new \RuntimeException(
                "wprism: $where names a column that is not a declared authored columns{} entry — a codec decodes "
                . 'CAPTURED bytes, and a ref, runtime, derived or env column is never carried into canonical state, '
                . 'so the declaration would have nothing to act on'
            );
        }
        if ($slugColumn === $column) {
            throw new \RuntimeException(
                "wprism: $where names this table's slug_column — a slug is the human-readable half of a canonical "
                . 'filename and must stay a plain scalar; a decoded container has no filename spelling'
            );
        }
        if (in_array($column, $identityColumns, true)) {
            throw new \RuntimeException(
                "wprism: $where names an identity column — identity is derived from the column's own value, so "
                . 'decoding and re-encoding it would make the derived uuid depend on this engine\'s serializer '
                . 'rather than on the authored fact'
            );
        }
        if (!is_array($codec) || array_is_list($codec)) {
            throw new \RuntimeException(
                "wprism: $where must be an object declaring exactly {container, leaves}"
            );
        }
        $keys = array_keys($codec);
        sort($keys, SORT_STRING);
        if ($keys !== self::CODEC_KEYS && $keys !== self::VALUE_CODEC_KEYS && $keys !== ColumnValueCases::CODEC_KEYS) {
            throw new \RuntimeException(
                "wprism: $where declares [" . implode(', ', array_map('strval', $keys)) . '] but a column codec is '
                . 'exactly {container, leaves} — both are required because a codec with an implied container is a '
                . 'declaration whose meaning changes the next time the engine grows one'
            );
        }
        if (!in_array($codec['container'], self::CONTAINERS, true)) {
            throw new \RuntimeException(
                "wprism: $where declares container=" . var_export($codec['container'], true)
                . ' but the column container vocabulary is closed and engine-owned ('
                . implode(', ', self::CONTAINERS) . ') — only the engine can decode a container, so a new one is '
                . 'an engine change with its own engine feature, not a manifest declaration'
            );
        }
        if ($codec['container'] === 'php_serialized_or_text'
            && !in_array(self::MIXED_FEATURE, (array) ($manifest['engine_features'] ?? []), true)) {
            throw new \RuntimeException(
                "wprism: $where declares container='php_serialized_or_text', which the engine feature '"
                . self::MIXED_FEATURE . "' gates — declare it in this manifest's top-level \"engine_features\" "
                . 'list. An engine that does not implement the feature refuses the adapter by feature name '
                . 'instead of treating a scalar as serialized bytes or a serialized map as opaque text'
            );
        }
        if ($codec['container'] === 'json'
            && !in_array(self::JSON_FEATURE, (array) ($manifest['engine_features'] ?? []), true)) {
            throw new \RuntimeException("wprism: $where container='json' requires the engine feature '" . self::JSON_FEATURE . "'");
        }
        if ($keys === self::VALUE_CODEC_KEYS || $keys === ColumnValueCases::CODEC_KEYS) {
            if (!in_array(self::VALUES_FEATURE, (array) ($manifest['engine_features'] ?? []), true)) {
                throw new \RuntimeException("wprism: $where.value requires engine feature '" . self::VALUES_FEATURE . "'");
            }
            if ($codec['container'] === 'php_serialized_or_text') {
                throw new \RuntimeException("wprism: $where.value requires a strict container; mixed scalar arms do not carry value contracts");
            }
            if ($keys === ColumnValueCases::CODEC_KEYS) {
                if (!in_array(ColumnValueCases::FEATURE, (array) ($manifest['engine_features'] ?? []), true)) {
                    throw new \RuntimeException("wprism: $where.value_cases requires engine feature '" . ColumnValueCases::FEATURE . "'");
                }
                ColumnValueCases::validate($codec['value_cases'], $table, $values, "$where.value_cases");
                self::assert_input_identity($codec, $table, $where);
                return;
            }
            if (!is_array($codec['value']) || array_is_list($codec['value']) || ($codec['value']['class'] ?? '') !== 'authored') {
                throw new \RuntimeException("wprism: $where.value requires an authored value contract");
            }
            $values->validate($codec['value'], "$where.value");
            self::assert_input_identity($codec, $table, $where);
            return;
        }
        if (!in_array($codec['leaves'], self::LEAVES, true)) {
            throw new \RuntimeException(
                "wprism: $where declares leaves=" . var_export($codec['leaves'], true)
                . ' but the leaf codec vocabulary is closed and engine-owned (' . implode(', ', self::LEAVES)
                . ') — "text" is the ordinary home/uploads URL and query-reference pass'
            );
        }
    }

    public static function has_input_files(array $codec): bool {
        $rules = isset($codec['value']) ? [$codec['value']] : array_column($codec['value_cases']['cases'] ?? [], 'value');
        foreach ($rules as $rule) if (InputFileBinding::declared($rule)) return true;
        return false;
    }

    private static function assert_input_identity(array $codec, array $table, string $where): void {
        if (($table['identity']['mode'] ?? '') === 'composite_ref' && self::has_input_files($codec)) {
            throw new \RuntimeException("wprism: $where input files require ordinary typed-table identity");
        }
    }

    /**
     * The declared codecs for ONE table, merged across a pin set.
     *
     * Last pinned manifest wins per table, matching
     * ContentAttributeRuleResolver's precedence for block/shortcode attribute
     * registries and for the same reason: how a plugin frames its own column is
     * a structural fact about that plugin, not a site-local policy choice, so
     * there is no `site.wprism.json` override and the later pin simply replaces the
     * earlier declaration whole.
     *
     * @param list<array<string,mixed>> $manifests
     * @return array<string,array{container:string,leaves?:string,value?:array}> column => codec
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
     * @param array{container:string,leaves?:string,value?:array} $codec
     */
    public static function capture_value(
        mixed $raw,
        array $codec,
        object $tokens,
        string $where,
        string $key = '',
        array $rule = []
    ): mixed {
        $decoded = self::decode($raw, $codec, $where, 'captured');
        if ($decoded['kind'] === 'null') {
            return null;
        }
        $piiSubject = isset($codec['value'])
            ? AuthoredValueCodec::pii_subject($decoded['value'], $codec['value'], false, $where) : null;
        $secretSubject = isset($codec['value'])
            ? AuthoredValueCodec::secret_subject($decoded['value'], $codec['value'], false, $where) : $decoded['value'];
        self::assert_clearance($key, $secretSubject, $rule, $where, $piiSubject);
        if ($decoded['kind'] === 'text') {
            return $tokens->tokenize_text($decoded['value']);
        }
        $value = isset($codec['value'])
            ? AuthoredValueCodec::capture($decoded['value'], $codec['value'], $tokens,
                static function (): void { throw new \RuntimeException('wprism: column value refuses an unmapped reference'); }, $where)
            : $tokens->plain_data_capture($decoded['value']);
        return $decoded['kind'] === 'json'
            ? StructuredValue::encode($value, ['json_encoded' => true], $where)
            : serialize($value);
    }

    /** Decode canonical or captured bytes for the shared recursive clearance gate. */
    public static function decode_for_clearance(
        mixed $bytes,
        array $codec,
        string $where,
        string $side = 'authored'
    ): mixed {
        return self::decode($bytes, $codec, $where, $side)['value'];
    }

    /** Immutable compilation and lint validate references without resolving environment bindings. */
    public static function decode_canonical_value(mixed $bytes, array $codec, string $where): mixed {
        $value = self::decode_for_clearance($bytes, $codec, $where);
        if (isset($codec['value'])) AuthoredValueCodec::assert_value($value, $codec['value'], true, $where);
        return $value;
    }

    /**
     * Apply direction: canonical bytes -> storage bytes.
     *
     * @param array{container:string,leaves?:string,value?:array} $codec
     */
    public static function apply_value(mixed $canonical, array $codec, object $tokens, string $where, ?callable $inputFile = null): mixed {
        $decoded = self::decode($canonical, $codec, $where, 'authored');
        if ($decoded['kind'] === 'null') {
            return null;
        }
        if ($decoded['kind'] === 'text') {
            return $tokens->detokenize_text($decoded['value']);
        }
        $value = isset($codec['value'])
            ? AuthoredValueCodec::apply($decoded['value'], $codec['value'], $tokens, $where, $inputFile)
            : $tokens->plain_data_apply($decoded['value']);
        return $decoded['kind'] === 'json'
            ? StructuredValue::encode($value, ['json_encoded' => true], $where)
            : serialize($value);
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
     * @param array{container:string,leaves?:string,value?:array} $codec
     * @return array{kind:'container'|'json',value:array<mixed>}|array{kind:'text',value:string}|array{kind:'null',value:null}
     */
    private static function decode(mixed $bytes, array $codec, string $where, string $side): array {
        if (array_key_exists(ColumnValueCases::FIELD, $codec)) {
            throw new \RuntimeException("wprism: $where requires row selection before decoding a column value case");
        }
        $container = (string) $codec['container'];
        if ($container === 'php_serialized_or_text' && $bytes === null) {
            return ['kind' => 'null', 'value' => null];
        }
        if (!is_string($bytes)) {
            // Refused rather than coerced. A codec is a claim about how a
            // column's BYTES are framed, and a column holding an integer or a
            // NULL on this environment is a fact about the declaration being
            // wrong here, not a value to cast into shape.
            throw new \RuntimeException(
                "wprism: $where declares the '$container' column codec but the $side value is "
                . get_debug_type($bytes) . ', not a string — a container codec decodes stored bytes'
            );
        }
        $decoded = $container === 'json'
            ? StructuredValue::decode($bytes, ['json_encoded' => true], $where)
            : PlainData::decode($bytes, $where);
        if ($container === 'php_serialized_or_text' && is_string($decoded) && $decoded === $bytes) {
            return ['kind' => 'text', 'value' => $decoded];
        }
        // The shared associative JSON decoder loses duplicate keys and some
        // object/list distinctions. Exact re-encoding refuses those shapes,
        // alternate spellings and numeric precision loss before any rewrite.
        $reencoded = $container === 'json'
            ? StructuredValue::encode($decoded, ['json_encoded' => true], $where)
            : (is_object($decoded) ? null : @serialize($decoded));
        if ($reencoded !== $bytes) {
            throw new \RuntimeException(
                "wprism: $where declares the '$container' column codec, but re-encoding the $side value does not "
                . 'reproduce its bytes exactly, so the decode cannot be trusted — refusing BEFORE any substitution '
                . 'rather than writing back a container this engine may have mis-read. Either the value is not '
                . "canonical $container data, or the column is misdeclared"
            );
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException(
                "wprism: $where declares the '$container' column codec but the $side value decodes to "
                . get_debug_type($decoded) . ' — a column codec exists to reach the string leaves INSIDE a '
                . 'container; a scalar column is already tokenized correctly without one'
            );
        }
        if ($container === 'json') PlainData::assert($decoded, $where);
        return ['kind' => $container === 'json' ? 'json' : 'container', 'value' => $decoded];
    }

    /** The codec owns framing, so clearance must inspect the decoded value. */
    private static function assert_clearance(string $key, mixed $value, array $rule, string $where, ?array $piiSubject = null): void {
        if (empty($rule['allow_secret'])) {
            $label = Secrets::clearance_match_deep($key, $value);
            if ($label !== null) {
                throw new \RuntimeException(
                    "wprism: secret guard tripped — $where decodes to a value containing a $label but is classified "
                    . "authored; refusing to capture it into state/.\n"
                    . 'If this is really a secret, reclassify the column runtime/derived/env instead of authored.'
                );
            }
        }
        if (empty($rule['allow_pii']) && ($piiSubject['present'] ?? true)) {
            $label = PersonalData::match_deep($key, $piiSubject === null ? $value : $piiSubject['value']);
            if ($label !== null) {
                throw new \RuntimeException(
                    "wprism: PII guard tripped — $where decodes to a value containing $label but is classified "
                    . 'authored; refusing capture without an exact reviewed allow_pii=true rule'
                );
            }
        }
    }
}
