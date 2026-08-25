<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/JsonRefs.php';
require_once __DIR__ . '/../Kernel/ReferenceRules.php';
require_once __DIR__ . '/../Kernel/Secrets.php';

/**
 * `body_refs` and the `json` post-type body mode — structured post-body
 * reference paths (WP-6.5).
 *
 * WHAT WAS MISSING, MEASURED. `tools/engine-gaps.json` records the demand as
 * the primitive `structured_post_body_reference_paths`, blocked candidate
 * WPForms Lite 2.0.0.4/2.0.0.5, coordinate `post_types.wpforms.body`: a
 * `wpforms` post's `post_content` is a JSON document that carries cross-entity
 * references inside it. `post_type_body_modes` was closed at
 * `{blocks, verbatim, serialized}` and none of the three addresses that.
 * `blocks` runs a block parser over a document that has no blocks; `serialized`
 * decodes PHP serialization, not JSON; `verbatim` preserves the bytes, which
 * means preserving a SOURCE-LOCAL id and pointing it at the wrong entity on any
 * target whose ids differ. Omitting `body` is not a fourth option: it defaults
 * to `blocks` (`PostTypeGrammar::defaultBodyMode()`), so a JSON body was
 * mis-read rather than left alone.
 *
 * WHAT THE 2026-08-25 LIVE RECON MEASURED, AND WHY EACH FACT IS A RULE HERE.
 * Every entity below was authored through WPForms' own write paths
 * (`wpforms()->obj('form')->add()/update()`, includes/class-form.php:535/:671),
 * never by hand:
 *
 *   1. `$.settings.confirmations.<n>.page` holds a PAGE post id as a JSON
 *      STRING (measured `"page": "4"` pointing at page 4) — so the resolved id
 *      must be written back AS A STRING or an untouched round trip changes the
 *      post's bytes. That is `attr_id_codecs`' problem one level up, and its
 *      answer is reused rather than restated: the per-path `cast: "string"`
 *      member the shipped `json_refs` dialect already carries
 *      (`StructuredReferenceCodec::apply()` :75).
 *   2. The SAME key legitimately holds the non-numeric literal
 *      `previous_page` — includes/class-process.php:1553-1562 branches on
 *      exactly that string before `get_permalink((int) $confirmation['page'])`.
 *      A rule that coerced this slot to an int would destroy the sentinel and
 *      silently repoint the confirmation at post 0. `sentinels` is the declared
 *      literal set per path; an undeclared non-numeric value REFUSES rather
 *      than being guessed at, because the two failure modes — "the adapter
 *      forgot a sentinel" and "this really is a reference" — have opposite
 *      remedies and the engine cannot tell them apart.
 *   3. The top-level `id` key is OPTIONAL AND TYPE-VARIANT, and this is the
 *      fact the ledger's own sentence got wrong. Three write paths, three
 *      shapes, one site, one session: ABSENT on the template path (post 6,
 *      `add()` + `update()` from `get(..., content_only)`), INT on the
 *      `['builder' => false]` path (`"id":12`, includes/class-form.php:611-639,
 *      the WP Abilities integration), STRING on the real builder save
 *      (`"id":"14"`, ajax-actions.php:36-52 rebuilds a jQuery serializeArray()
 *      FLAT input list, so every leaf reaching update() is a string).
 *      This grammar's answer is PRESERVATION, not normalisation: the mode
 *      rewrites DECLARED PATHS ONLY and re-encodes everything else from the
 *      decoded document, so an undeclared `$.id` survives absent-as-absent,
 *      int-as-int and string-as-string with no rule written for it. An adapter
 *      that DOES declare it must declare one type, and a source that disagrees
 *      refuses BY NAME (assert_source_type()) — which makes the three write
 *      paths visible to the author instead of silently mis-typing two of them.
 *
 * THE IDENTITY ROUND-TRIP PRECONDITION, AND WHY IT IS FIRST. Before any
 * substitution, the decoded document is re-encoded and compared to the input
 * BYTE FOR BYTE (assert_reencodable()). A post body is not a meta row: it is
 * folded into `Canon::post_hash_basis()` and it is what an operator reads in a
 * diff, so a mode that could not reproduce an untouched body would show every
 * adopted form as changed forever. Two hazards make this a real check rather
 * than a formality, and both were measured on the recon captures: JSON key
 * ORDER is preserved by PHP's decode/encode pair but an object whose keys are
 * `"0","1","2"` decodes to a PHP list and re-encodes as a JSON ARRAY, and an
 * empty JSON OBJECT `{}` decodes to `[]` and re-encodes as `[]`. Both change
 * bytes; both refuse here, before a token is written. The flags are `wp_json_
 * encode()`'s defaults (escaped slashes, escaped unicode) because that is what
 * the plugin wrote — the four captured WPForms bodies carry
 * `"http:\/\/localhost:9620\/..."` — and a plugin that encoded differently
 * refuses with the same named diagnostic rather than being accommodated.
 *
 * WHAT THIS PRIMITIVE DOES NOT TOUCH, STATED SO IT CANNOT BE ASSUMED. It
 * operates on `post_content` that is a JSON document. It is not block
 * attributes (`attr_id_codecs`, WP-6.1, a separate declaration over a separate
 * parser) and it does not reach `serialize_block_attributes()`' `"`
 * escaping or the `wp_unslash()` hazard the same recon measured on the
 * embedding page — nothing here passes through `wp_update_post()`. It also
 * rewrites ONLY declared reference paths: the recon measured two
 * environment-bound values in the same bodies that this mode carries across
 * unchanged — `$.settings.confirmations.<n>.redirect` bakes the source site's
 * absolute home URL and `$.settings.notifications.<n>.sender_name` bakes
 * `get_bloginfo('name')` (includes/class-form.php:622) — so capture WARNS when
 * the body contains this environment's home URL, exactly as the `verbatim` arm
 * has always done, rather than pretending the mode made the body portable.
 */
final class BodyRefGrammar {
    /**
     * The engine feature that gates both halves of this primitive
     * (spec/repo-format.md § v3.20).
     *
     * Named here rather than in `AdapterContractGrammar::IMPLEMENTED_FEATURES`
     * for the reason `ManifestGrammar::INVALIDATE_VOCABULARY_FEATURE` gives for
     * its own: Policy and Adapter are both ABOVE Grammar on
     * `tools/modules.json`'s ladder, so the constant lives at the bottom and the
     * two readers above it read it, instead of two spellings that agree until
     * one of them moves.
     */
    public const FEATURE = 'structured-body-refs/v1';

    /** The top-level section this feature claims. */
    public const SECTION = 'body_refs';

    /**
     * The `post_types.<type>.body` value this feature admits.
     *
     * A member of a vocabulary that already exists rather than a new top-level
     * switch, because the question it answers — "how is this post type's body
     * read" — is exactly the question the existing three answer, and a second
     * switch beside it would make two declarations able to disagree.
     * `PostTypeGrammar` keeps the vocabulary; the GATE is asked there with the
     * declaring manifest in hand, which is why the base three stay byte for byte
     * what they were for every manifest that declares no feature.
     */
    public const BODY_MODE = 'json';

    /**
     * `json_encode()` flags for the canonical body.
     *
     * Zero, and deliberately: `wp_json_encode()` is `json_encode()` with no
     * flags, so escaped slashes and `\uXXXX` escaping are what a WordPress
     * plugin writes, and reproducing the plugin's own bytes is the entire
     * contract of this mode. Named rather than inlined because capture, apply
     * and the precondition must all use the same value — three literals would
     * agree until one of them did not.
     */
    private const ENCODE_FLAGS = 0;

    /**
     * Loud, load-time guard for one manifest's `body_refs` section.
     *
     * Every cross-check names a way the declaration could load and then do
     * nothing, which is the failure `AttrIdCodecGrammar::validate_attr_id_
     * codecs()` already refuses one primitive over: a section for a post type
     * that is not in `json` mode never runs, and a `json` post type with no
     * declared paths re-encodes a document and rewrites nothing, which is
     * `verbatim` with an extra refusal surface.
     *
     * The `{path, kind, cast}` triple itself is NOT validated here. It is handed
     * to `ReferenceRules::value_rule()` — the shipped validator that already
     * owns the minimal JSONPath dialect (`$`, `.`, `..`, `.*`), the reference
     * keyspace name grammar, the `cast: "string"` vocabulary and the
     * overlapping-path NFA intersection. So an author writing a body path gets
     * the refusals they already know from `post_meta`/`options`, verbatim, and
     * this engine has one dialect rather than a second one that drifts.
     *
     * @param array<string,mixed> $manifest
     */
    public static function validate_body_refs(array $manifest, string $label): void {
        $postTypes = is_array($manifest['post_types'] ?? null) ? $manifest['post_types'] : [];
        $section = $manifest[self::SECTION] ?? null;
        self::assert_body_mode_gate($manifest, $label);

        if ($section !== null) {
            if (!is_array($section) || array_is_list($section) || $section === []) {
                throw new \RuntimeException(
                    "duo: $label " . self::SECTION . ' must be a non-empty object keyed by post type, each value '
                    . 'an object declaring {json_refs} — an empty section declares a capability the adapter does '
                    . 'not use'
                );
            }
            foreach ($section as $postType => $decl) {
                self::validate_one((string) $postType, $decl, "$label " . self::SECTION . '.' . (string) $postType, $postTypes);
            }
        }

        // The other direction, and it is the half that would otherwise fail
        // SILENTLY at capture time on a live site rather than at load: a post
        // type in `json` mode with no declared paths decodes a document,
        // re-encodes it and rewrites nothing. That is `verbatim` plus a
        // round-trip refusal, and an adapter that meant `verbatim` should
        // declare it.
        foreach ($postTypes as $postType => $decl) {
            if (!is_array($decl) || ($decl['body'] ?? null) !== self::BODY_MODE) {
                continue;
            }
            if (!is_array($section[(string) $postType] ?? null)) {
                throw new \RuntimeException(
                    "duo: $label declares post_types." . (string) $postType . '.body=' . self::BODY_MODE
                    . ' but no ' . self::SECTION . '.' . (string) $postType . ' entry — the mode exists to rebind '
                    . 'declared reference paths inside the document, so a json body with no paths is `verbatim` '
                    . 'with an extra refusal surface. Declare the paths, or declare body=verbatim'
                );
            }
        }
    }

    /**
     * Whether ONE manifest declared this feature.
     *
     * The single predicate both consumers ask, and the reason it is public: the
     * `json` body mode is admitted by `PostTypeGrammar::admitted_body_modes()`
     * (so the refusal a typo gets prints the vocabulary that applies to THAT
     * manifest) and gated by `assert_body_mode_gate()` below, and those two
     * answers may never disagree. Owning the read here also keeps the number of
     * shipped files that read `engine_features` down to the ones that
     * genuinely consult the channel — the property
     * `regress_spec_v3_dry_run.php`'s V3-FEAT reader census exists to ratchet.
     *
     * @param array<string,mixed> $manifest
     */
    public static function declares_feature(array $manifest): bool {
        foreach ((array) ($manifest['engine_features'] ?? []) as $feature) {
            if ($feature === self::FEATURE) {
                return true;
            }
        }

        return false;
    }

    /**
     * The body-MODE half of the gate, asked ONCE with the declaring manifest in
     * hand, and asked HERE rather than where the mode's vocabulary lives.
     *
     * `PostTypeGrammar::validate_post_type_contracts()` runs before
     * `AdapterContractGrammar::validate_adapter_contract()`, so refusing there
     * would pre-empt § v3.2/§ v3.3's three verdicts with a fourth sentence: a
     * `spec_version: 2` manifest declaring the mode AND the section would be
     * told about an engine feature when what is actually wrong is the version
     * its whole document declares. By the time this runs, all three have had
     * their say, so the only manifest that reaches this refusal is one whose
     * `body_refs` section was admissible or absent — which is exactly the case a
     * top-level key cannot answer for, and the reason the mode needed its own
     * gate at all.
     *
     * `ManifestGrammar::assert_invalidate_feature_gate()` is the shape this
     * follows; the difference is placement, and the difference in placement is
     * that this feature CLAIMS a top-level key and that one does not, so this
     * one has three earlier verdicts it must not step on.
     *
     * @param array<string,mixed> $manifest
     */
    private static function assert_body_mode_gate(array $manifest, string $label): void {
        if (self::declares_feature($manifest)) {
            return;
        }
        foreach ((array) ($manifest['post_types'] ?? []) as $postType => $decl) {
            if (!is_array($decl) || ($decl['body'] ?? null) !== self::BODY_MODE) {
                continue;
            }
            throw new \RuntimeException(
                "duo: $label declares post_types." . (string) $postType . ".body='" . self::BODY_MODE
                . "', which the engine feature '" . self::FEATURE . "' gates — declare it in this manifest's "
                . 'top-level "engine_features" list (which itself requires spec_version 3, spec/repo-format.md '
                . '§ v3.2). An engine that does not implement the feature refuses the adapter by feature name '
                . 'instead of mis-reading the declaration, which is what lets this body mode ship with no '
                . 'version bump'
            );
        }
    }

    /**
     * @param array<string,mixed> $postTypes this manifest's own post_types section
     */
    private static function validate_one(string $postType, mixed $decl, string $where, array $postTypes): void {
        $mode = is_array($postTypes[$postType] ?? null) ? ($postTypes[$postType]['body'] ?? null) : null;
        if ($mode !== self::BODY_MODE) {
            throw new \RuntimeException(
                "duo: $where names a post type this manifest does not declare as post_types.$postType.body="
                . self::BODY_MODE . ' — body reference paths refine a body MODE, so paths with no json body '
                . 'beneath them would never run. The codec\'s post type is checked against THIS manifest and not '
                . 'the loaded pin set, so one adapter cannot redirect another adapter\'s body'
            );
        }
        if (!is_array($decl) || array_is_list($decl)) {
            throw new \RuntimeException("duo: $where must be an object declaring {json_refs} and optional {sentinels}");
        }
        $unknown = array_diff(array_map('strval', array_keys($decl)), ['json_refs', 'sentinels']);
        if ($unknown !== []) {
            sort($unknown, SORT_STRING);
            throw new \RuntimeException(
                "duo: $where declares [" . implode(', ', $unknown) . '] — a body reference declaration is exactly '
                . '{json_refs} plus an optional {sentinels}. `key_refs` in particular is NOT admitted: an id-KEYED '
                . 'map inside a post body has no measured demand, and this engine does not claim a shape it has '
                . 'never seen'
            );
        }
        $refs = $decl['json_refs'] ?? null;
        if (!is_array($refs) || !array_is_list($refs) || $refs === []) {
            throw new \RuntimeException(
                "duo: $where.json_refs must be a non-empty list of {path, kind} entries"
            );
        }
        // ONE dialect. Everything about the triple — path syntax, keyspace name,
        // the `cast` vocabulary, and the overlapping-path refusal — is the
        // shipped validator's answer, reported at the author's own locator
        // ("$where.json_refs[0]").
        ReferenceRules::value_rule(['json_refs' => $refs], $where);

        $declaredPaths = [];
        foreach ($refs as $ref) {
            $declaredPaths[(string) $ref['path']] = true;
        }

        if (!array_key_exists('sentinels', $decl)) {
            return;
        }
        $sentinels = $decl['sentinels'];
        if (!is_array($sentinels) || array_is_list($sentinels) || $sentinels === []) {
            throw new \RuntimeException(
                "duo: $where.sentinels must be a non-empty object keyed by a declared json_refs path, each value a "
                . 'non-empty list of the literal strings that path legitimately holds instead of a reference'
            );
        }
        foreach ($sentinels as $path => $literals) {
            $path = (string) $path;
            if (!isset($declaredPaths[$path])) {
                throw new \RuntimeException(
                    "duo: $where.sentinels names path '$path', which none of this post type's json_refs entries "
                    . 'declares — a sentinel set over an undeclared path is never consulted, which is exactly the '
                    . 'declared-but-never-applied failure the reference grammar refuses one level down'
                );
            }
            if (!is_array($literals) || !array_is_list($literals) || $literals === []) {
                throw new \RuntimeException("duo: $where.sentinels['$path'] must be a non-empty list of strings");
            }
            $seen = [];
            foreach ($literals as $i => $literal) {
                if (!is_string($literal) || $literal === '') {
                    throw new \RuntimeException(
                        "duo: $where.sentinels['$path'][$i] must be a non-empty string — a sentinel is a LITERAL "
                        . 'the plugin branches on, so it is compared identically and never coerced'
                    );
                }
                // A numeric sentinel cannot be distinguished from a reference at
                // the same path, and admitting one would make the rewrite depend
                // on which check ran first.
                if (preg_match('/^[0-9]+$/D', $literal) === 1) {
                    throw new \RuntimeException(
                        "duo: $where.sentinels['$path'][$i] is the numeric literal '$literal' — a sentinel exists "
                        . 'to name a value that is NOT a reference, and a numeric one is indistinguishable from '
                        . 'the id this path resolves'
                    );
                }
                if (isset($seen[$literal])) {
                    throw new \RuntimeException("duo: $where.sentinels['$path'] repeats '$literal'");
                }
                $seen[$literal] = true;
            }
        }
    }

    /**
     * postType => {json_refs, sentinels}, merged across a pin set.
     *
     * Last pinned manifest wins per POST TYPE, and replacement is WHOLE for the
     * reason `AttrIdCodecGrammar::rules()` gives: the paths and the sentinel
     * sets over them are one declaration about one body, so a later pin that
     * redeclares the paths must drop the earlier pin's sentinels too, or the new
     * paths would be read through the old one's literals.
     *
     * @param list<array<string,mixed>> $manifests
     * @return array<string,array{json_refs:list<array<string,mixed>>,sentinels:array<string,list<string>>}>
     */
    public static function rules(array $manifests): array {
        $out = [];
        foreach ($manifests as $manifest) {
            foreach ((array) ($manifest[self::SECTION] ?? []) as $postType => $decl) {
                if (!is_array($decl) || !is_array($decl['json_refs'] ?? null)) {
                    continue;
                }
                $out[(string) $postType] = [
                    'json_refs' => array_values($decl['json_refs']),
                    'sentinels' => is_array($decl['sentinels'] ?? null) ? $decl['sentinels'] : [],
                ];
            }
        }
        return $out;
    }

    /**
     * Decode a json body, proving the identity round trip BEFORE anything is
     * substituted.
     *
     * Refusing here rather than reporting a diff later is the same decision
     * `ColumnCodecGrammar` made for a container it cannot re-encode and
     * `AttrIdCodecGrammar::assert_source_type()` made for a type it cannot
     * reproduce: a codec that cannot round-trip an UNTOUCHED value is
     * mis-declared, and the only answer that cannot corrupt authored content is
     * to say so with the offending document named.
     *
     * @return array<mixed> the decoded document
     */
    public static function decode(string $body, string $context): array {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                "duo: $context declares body=" . self::BODY_MODE . ' but its post_content is not a JSON document ('
                . $e->getMessage() . ') — the mode decodes the body to reach declared reference paths, so a body '
                . 'it cannot decode is either the wrong body mode or content that predates the plugin version the '
                . "adapter's version_range claims"
            );
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException(
                "duo: $context declares body=" . self::BODY_MODE . ' but its post_content decodes to '
                . get_debug_type($decoded) . ' rather than a JSON object or array — a reference PATH needs a '
                . 'container to address'
            );
        }
        self::assert_reencodable($decoded, $body, $context);

        return $decoded;
    }

    /**
     * The identity round-trip precondition: re-encode == input, byte for byte.
     *
     * @param array<mixed> $decoded
     */
    private static function assert_reencodable(array $decoded, string $body, string $context): void {
        $reencoded = json_encode($decoded, self::ENCODE_FLAGS);
        if ($reencoded === $body) {
            return;
        }
        throw new \RuntimeException(
            "duo: $context declares body=" . self::BODY_MODE . ' but its post_content does not survive a '
            . 'decode/re-encode round trip unchanged — apply re-encodes the document, so capturing this body would '
            . 'change the post\'s bytes on a target where nothing was substituted. Refusing before substitution. '
            . 'The measured causes are a JSON object whose keys are "0","1","2"… (which decodes to a PHP list and '
            . 're-encodes as a JSON ARRAY), an empty object {} (which re-encodes as []), and an encoder that did '
            // Written WITHOUT the call parentheses on purpose: this file is in
            // `duo manifest-validate`'s boot() load set, and that command's
            // WordPress-free guard (regress_manifest_validate.sh:212) scans for
            // `wp_*(` in non-comment lines. A refusal that NAMES a WordPress
            // function is prose, not a reach — but the guard cannot tell a
            // string from a call, and weakening the guard to make room for one
            // sentence would be the wrong trade.
            . 'not use `wp_json_encode`\'s defaults (escaped slashes, escaped unicode)'
        );
    }

    /**
     * Capture direction: declared reference paths become tokens; every other
     * byte of the document is reproduced from the decode.
     *
     * @param array{json_refs:list<array<string,mixed>>,sentinels:array<string,list<string>>} $rule
     * @param callable(int,string):?string $idToToken
     * @param callable(string):void $warn
     */
    public static function capture(string $body, array $rule, callable $idToToken, callable $warn, string $context): string {
        $decoded = self::decode($body, $context);
        // The closest analogue is `serialized`, not `blocks`: a json body is
        // authored plugin CONFIGURATION rather than prose, so a credential in it
        // is a credential rather than a sentence that mentions one, and
        // PostCapture's serialized arm refuses on exactly this basis.
        $secretLabel = Secrets::hard_match_deep($decoded);
        if ($secretLabel !== null) {
            throw new \RuntimeException(
                "duo: $context contains a $secretLabel; refusing to capture json authored configuration"
            );
        }
        self::walk($decoded, $rule, function (mixed $value, array $ref, string $locator) use (
            $idToToken,
            $warn,
            $context
        ): mixed {
            self::assert_source_type($value, $ref, $locator, $context);
            $id = (int) $value;
            $token = $idToToken($id, (string) $ref['kind']);
            if ($token === null) {
                $warn(
                    "body_refs path '$locator' in $context: unmapped " . (string) $ref['kind'] . " id $id dropped "
                    . '(dangling reference)'
                );
            }
            // A missing mapping is a null canonical value, never a raw id —
            // StructuredReferenceCodec::capture()'s rule, restated because a
            // body carries the same hazard as a meta value and must not be
            // allowed a softer one.
            return $token;
        });

        return self::encode($decoded, $context);
    }

    /**
     * Apply direction: tokens become target-local ids in their declared JSON
     * type; sentinels, unset values and every undeclared position are
     * reproduced.
     *
     * @param array{json_refs:list<array<string,mixed>>,sentinels:array<string,list<string>>} $rule
     * @param callable(string):int $tokenToId
     */
    public static function apply(string $body, array $rule, callable $tokenToId, string $context): string {
        $decoded = self::decode($body, $context);
        self::walk($decoded, $rule, function (mixed $value, array $ref, string $locator) use (
            $tokenToId,
            $context
        ): mixed {
            if (!is_string($value) || !str_starts_with($value, '{{')) {
                // A raw id at a declared path in CANONICAL state is the exact
                // condition `Lint`'s `unrewritten_registered_ref` reports.
                // Casting it here would write a source-local id onto the target
                // in the declared type and call it a success.
                throw new \RuntimeException(
                    "duo: $context repository body path '$locator' holds " . var_export($value, true)
                    . ' where a {{...}} reference token was declared — canonical state that still carries a '
                    . 'source-local id at a declared reference path is `wp duo lint`\'s unrewritten_registered_ref '
                    . 'finding, and applying it would bind this body to whatever entity happens to hold that id here'
                );
            }
            $id = $tokenToId($value);

            return self::encode_id($id, $ref);
        });

        return self::encode($decoded, $context);
    }

    /**
     * Every declared reference position in a decoded body that is a REWRITE
     * CANDIDATE, read-only and throwing nothing.
     *
     * This is the lint-side and reporting-side half of the traversal below.
     * Read-only because `Review` sits ABOVE `Grammar` on `tools/modules.json`'s
     * ladder: this class hands back positions and the linter builds its own
     * findings, rather than this class reaching up into `LintFinding`.
     * Throwing nothing because the two conditions the write path refuses — an
     * undecodable body and a declared path resolving to a container — already
     * refuse at capture with their own named diagnostics, and a linter that
     * raised a second, differently-worded copy of a refusal would make the
     * operator reconcile two vocabularies for one fact.
     *
     * @param array<mixed> $decoded
     * @param array{json_refs:list<array<string,mixed>>,sentinels:array<string,list<string>>} $rule
     * @return list<array{value:mixed,ref:array<string,mixed>,locator:string}>
     */
    public static function reference_positions(array $decoded, array $rule): array {
        $positions = [];
        foreach ((array) ($rule['json_refs'] ?? []) as $ref) {
            if (!is_array($ref) || !is_string($ref['path'] ?? null)) {
                continue;
            }
            $path = (string) $ref['path'];
            $sentinels = array_values((array) ($rule['sentinels'][$path] ?? []));
            JsonRefs::walk(
                $decoded,
                JsonRefs::parse_path($path),
                function (&$container, $key, string $locator) use ($ref, $sentinels, &$positions): void {
                    $value = $container[$key];
                    if (is_array($value) || !self::is_rewrite_candidate($value, $sentinels)) {
                        return;
                    }
                    $positions[] = ['value' => $value, 'ref' => $ref, 'locator' => $locator];
                },
                ''
            );
        }

        return $positions;
    }

    /**
     * The one traversal capture and apply share.
     *
     * Sentinels, the WordPress unset convention and the container refusal are
     * decided HERE, once, and `reference_positions()` above answers from the
     * same predicate, so capture, apply and lint can never disagree about which
     * leaves are references — a disagreement would surface as a lint finding
     * apply refuses to act on, or worse, the reverse.
     *
     * @param array<mixed> $decoded
     * @param array{json_refs:list<array<string,mixed>>,sentinels:array<string,list<string>>} $rule
     * @param callable(mixed,array<string,mixed>,string):mixed $rewrite
     */
    private static function walk(array &$decoded, array $rule, callable $rewrite): void {
        foreach ($rule['json_refs'] as $ref) {
            $path = (string) $ref['path'];
            $sentinels = array_values((array) ($rule['sentinels'][$path] ?? []));
            JsonRefs::walk(
                $decoded,
                JsonRefs::parse_path($path),
                function (&$container, $key, string $locator) use ($ref, $sentinels, $rewrite): void {
                    $value = $container[$key];
                    if (is_array($value)) {
                        throw new \RuntimeException(
                            "duo: body_refs path '" . (string) $ref['path'] . "' resolved to a container at "
                            . "'$locator' rather than a scalar reference — a reference path addresses ONE id, so "
                            . 'this declaration addresses the wrong level of the document'
                        );
                    }
                    if (!self::is_rewrite_candidate($value, $sentinels)) {
                        return;
                    }
                    $container[$key] = $rewrite($value, $ref, $locator);
                },
                ''
            );
        }
    }

    /**
     * Whether one declared leaf is a value this codec rewrites at all.
     *
     * Two exemptions, in this order, and the order is the contract. ABSENCE
     * first: `null`/`''`/`0`/`"0"` is WordPress's and plugins' own "unset"
     * convention for an id field, treated identically to
     * `StructuredReferenceCodec::capture()`'s json_refs arm — a decision about
     * absence, decided before any question about type or literals. Then the
     * declared sentinel set, compared IDENTICALLY (`in_array(..., true)`)
     * because a sentinel is a literal the plugin branches on
     * (includes/class-process.php:1553-1562 compares `!== 'previous_page'`), so
     * coercing it here would make this engine and the plugin disagree about the
     * same value.
     *
     * @param list<string> $sentinels
     */
    private static function is_rewrite_candidate(mixed $value, array $sentinels): bool {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return false;
        }

        return !in_array($value, $sentinels, true);
    }

    /**
     * The capture-side type and sentinel precondition.
     *
     * TWO refusals, and both are the same argument in opposite directions.
     * Apply writes the DECLARED type, so a declaration that is false about this
     * source would change bytes on a target where nothing was substituted —
     * `AttrIdCodecGrammar::assert_source_type()`'s rule, reached through a
     * different door. And a non-numeric value that is not a declared sentinel is
     * refused rather than passed through, because "the adapter forgot a
     * sentinel" and "this path is not a reference after all" have opposite
     * remedies and only the author can tell them apart. The `previous_page`
     * literal is exactly why: it is measured on the SAME key that holds page id
     * "4", so silence here is a confirmation silently repointed at post 0.
     *
     * @param array<string,mixed> $ref
     */
    private static function assert_source_type(mixed $value, array $ref, string $locator, string $context): void {
        $declared = (($ref['cast'] ?? null) === 'string') ? 'string' : 'int';
        $numeric = is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1);
        if (!$numeric) {
            throw new \RuntimeException(
                "duo: $context body path '$locator' holds " . var_export($value, true) . ', which is neither a '
                . 'positive id nor a declared sentinel for this path — a reference path that meets a literal it '
                . 'was not told about cannot tell "the adapter forgot a sentinel" from "this is a reference after '
                . 'all", and the two have opposite remedies. Remedy: add the literal to body_refs.<type>.sentinels'
                . "['" . (string) $ref['path'] . "'], or correct the path"
            );
        }
        if ($declared === 'string' ? is_string($value) : is_int($value)) {
            return;
        }
        throw new \RuntimeException(
            "duo: $context body path '$locator' declares "
            . ($declared === 'string' ? 'cast=string' : 'no cast (the integer default)')
            . ', but this source stores it as ' . get_debug_type($value) . ' — apply writes the DECLARED type, so '
            . 'capturing this value would change the body\'s bytes on a target where nothing was substituted. '
            . 'Refusing before substitution: WPForms writes the same key as an int on one create path and a '
            . 'string on another (includes/class-form.php:611-639 vs includes/admin/ajax-actions.php:36-52), so a '
            . 'body whose type disagrees with the declaration is a write path the adapter has not accounted for'
        );
    }

    /**
     * Write one resolved local id back in its declared JSON type.
     *
     * The default arm is `(int)`, matching `StructuredReferenceCodec::apply()`
     * (:75) exactly, so `cast` means here what it already means everywhere else
     * this engine reads a declared reference path.
     *
     * @param array<string,mixed> $ref
     */
    public static function encode_id(int $id, array $ref): int|string {
        return (($ref['cast'] ?? null) === 'string') ? (string) $id : $id;
    }

    /** @param array<mixed> $decoded */
    private static function encode(array $decoded, string $context): string {
        $encoded = json_encode($decoded, self::ENCODE_FLAGS);
        if (!is_string($encoded)) {
            throw new \RuntimeException(
                "duo: $context json body could not be re-encoded (" . json_last_error_msg() . ')'
            );
        }

        return $encoded;
    }
}
