<?php
declare(strict_types=1);

namespace WPrism;

/**
 * The `declaration_evidence` section: typed, addressed evidence for why a
 * declaration says what it says (spec/repo-format.md § v3.14, WP-6.4).
 *
 * WHAT IT REPLACES, AND WHY PROSE WAS NOT ENOUGH
 * ---------------------------------------------
 * The repository already gates the declaration-to-rationale link, by the
 * crudest mechanism available: `regress_shipped_option_declarations.php:237`
 * asserts `str_contains($text, 'issue #3509')` over a manifest's free `notes`
 * prose, and then greps each option name out of the same blob. A grep for an
 * issue id inside prose is load-bearing regression coverage today. It has to
 * be, because there is nothing else: measured across the shipped library,
 * 208,064 of 328,013 manifest bytes are the `notes` region — 63% of the
 * library, validated by nothing, and polymorphic (a list in 14 manifests, an
 * object in 2), so no reader can even iterate it the same way twice.
 *
 * `wprism adapter-draft` already produces the missing structure and then throws it
 * away. Every candidate it proposes carries `evidence[]` rows of
 * `{source, locator, observation}` and the `questions[]` a live target must
 * answer (`cli/src/Adapter/AdapterDraft.php:1529-1530`), nested inert under
 * `_draft`. § v3.3 resolution 2 refuses `_draft` at `spec_version` 3 — rightly,
 * since an authoring sidecar must not enter the identity row a certificate
 * covers — so the moment a human ratifies a proposal into a real section, the
 * evidence behind it is deleted and what survives is, at best, a sentence in
 * `notes`. This section is where it survives instead.
 *
 * `notes` is UNTOUCHED. Nothing is migrated out of it, nothing is rewritten,
 * and no rule here reads it: this is a sibling, so an adapter that adopts the
 * section keeps every note it has, and the two can be reconciled by whoever
 * next edits that adapter for a product reason rather than by a mass rewrite
 * that would move all 16 adapter digests (AGENTS.md rule 2).
 *
 * WHY THIS FILE EXISTS AT ALL — THE NO-BUMP DEMONSTRATION
 * ------------------------------------------------------
 * `WPRISM_SPEC_VERSION` is 3 and STAYS 3. This is the first grammar section to
 * ship after v3, and it ships through § v3.2's declaration channel: the engine
 * implements the feature `structured-evidence/v1`, that feature claims this
 * key, and the three verdicts § v3.3's growth rule promises are what an author
 * meets — declared-and-implemented admits, declared-but-unimplemented refuses
 * BY FEATURE NAME, undeclared refuses as a misspelling. § v3.12's window-
 * closing condition asks for "at least one grammar section shipped post-v3
 * through `engine_features` with no version bump"; this is that section, and
 * `sandbox/tests/offline/policy/regress_structured_evidence.php` asserts the
 * unmoved define in the same run as the three verdicts, because a channel that
 * works while the integer quietly moved would have demonstrated nothing.
 *
 * THIS FILE HOLDS NO COPY OF THE CHANNEL
 * --------------------------------------
 * It never names the channel's key, never reads a declared feature list and
 * owns no part of the feature vocabulary: `AdapterContractGrammar` decides
 * whether this section is admitted at all and calls in afterwards, for values
 * only. That is a measured property, not a preference —
 * `regress_spec_v3_document.php:190-194` derives the channel's reader set from
 * the three shipped trees and refuses a SECOND reader, because a consumer that
 * could disagree with the one definition of `{name, since, keys}` is exactly
 * the silent mis-read the channel exists to remove.
 *
 * WHAT A CERTIFICATE SAYS ABOUT AN ADAPTER THAT ADOPTS THIS
 * --------------------------------------------------------
 * Nothing, and that is the stated posture rather than an oversight. § v3.3:
 * "a feature-claimed key has no reviewed answer to the only question the signer
 * asks", so `AdapterCertification::siteRatification()` still refuses this
 * section by name — "which this signer cannot classify as an entity or field
 * surface … teach the signer this section". An adapter carrying it therefore
 * LOADS everywhere and is not certifiable, a state an operator can see rather
 * than one they discover from a missing surface. Giving the key an arm in
 * `topLevelKeyPartition()` would also admit it with no feature declared, which
 * would delete the very demonstration above; the arm is a separate reviewed
 * decision and is deliberately not taken here.
 *
 * THE BLIND WALK CANNOT WAKE ANYTHING IN HERE
 * -------------------------------------------
 * `ReferenceKindGrammar::collect_ref_kind_claims()` walks the WHOLE manifest
 * and collects `ref`/`refs`/`json_refs`/`key_refs` at any depth
 * (`agent/src/Kernel/ReferenceKindGrammar.php:233-268`), skipping only four
 * top-level keys — this section is not among them, by choice. It does not need
 * to be: every object below has a CLOSED key set, none of whose members is a
 * trigger, and the one place an author picks a key is a target, whose head must
 * be a section the manifest declares (`assert_target()`), and no member of the
 * signer's partition is spelled `ref`, `refs`, `json_refs` or `key_refs`. So an
 * evidence record is inert under the blind walk structurally, not because a
 * fifth skip was added that the next section would have to remember.
 */
final class StructuredEvidence {
    /**
     * The top-level key, written once. `evidence` — the natural spelling — is
     * unavailable and must stay so: `AdapterSources::assert_out_of_tree_
     * contract()` refuses a site- or plugin-installed manifest that declares a
     * top-level `evidence` key at all, as one of twelve reserved authority
     * fields, because a data-only manifest may not carry its own trust root
     * (`agent/src/Adapter/AdapterSources.php:3813-3821`). A section an
     * out-of-tree adapter could never declare would be a section for shipped
     * manifests only, which is the opposite of this program's direction, so the
     * key names what it holds — evidence FOR a declaration — and leaves the
     * reserved word alone.
     */
    public const SECTION = 'declaration_evidence';

    /** One evidence row, closed. The draft's own row shape, unchanged. */
    private const EVIDENCE_KEYS = ['locator', 'observation', 'source'];

    /**
     * One answered-question row, closed.
     *
     * A draft's `questions[]` are bare strings, because a draft's question is
     * OPEN — it names a deferral for a human. Here they are pairs, because an
     * open question has no business in an installed manifest: a deferral
     * belongs in the sidecar that gets stripped, and a manifest that shipped
     * one would be back to prose with extra steps. So the shape itself is the
     * rule — a question reaches this section only with its answer attached.
     */
    private const ANSWERED_KEYS = ['answer', 'question'];

    /** A record's own key set: `evidence` mandatory, `answered` optional. */
    private const RECORD_REQUIRED = ['evidence'];
    private const RECORD_OPTIONAL = ['answered'];

    /**
     * This section's value grammar, published for `wprism manifest-validate
     * --emit-schema` (WP-6.6, spec/repo-format.md § v3.21).
     *
     * The three key sets are the private constants above, published rather than
     * restated — R-29 records every row member as permanent inside the adapter
     * digest, so a second spelling of them in the emitter would be a second
     * spelling of a frozen wire. `target` is stated in prose because the rule
     * that matters about it is not a key set at all: a target's HEAD must be a
     * top-level key this manifest still declares, which is the whole difference
     * between this section and a note that mentions one (:158-176).
     *
     * @return array<string,mixed>
     */
    public static function section_grammar(): array {
        return [
            'keyed_by' => 'the declaration each record is evidence FOR — a target whose head is a top-level key '
                . 'THIS manifest declares (`options.wpforms_settings`, `tables.acme_thing.pk`), never this '
                . 'section itself',
            'record' => [
                'required' => self::RECORD_REQUIRED,
                'optional' => self::RECORD_OPTIONAL,
            ],
            'evidence_row' => self::EVIDENCE_KEYS,
            'answered_row' => self::ANSWERED_KEYS,
            'rows' => 'each list is non-empty, each row carries exactly its key set, and every value is a '
                . 'non-empty string — an empty evidence[] asserts a declaration is founded and then declines '
                . 'to say on what',
            'validated_by' => 'WPrism\\StructuredEvidence::assert_section()',
        ];
    }

    /**
     * Validate the section, or refuse naming the exact path that is wrong.
     *
     * Reached only for a manifest whose grammar already admitted the key, so
     * every refusal below is about a VALUE. Iteration is over a sorted copy of
     * the targets, never the manifest's own key order: refusal order is
     * observable contract here exactly as it is in
     * `ManifestValidator::validate_manifest()` (`:40-42`), and a manifest
     * re-serialized by any tool that does not sort would otherwise get a
     * different first refusal for the same two defects.
     *
     * @param array<string,mixed> $manifest
     */
    public static function assert_section(string $name, array $manifest): void {
        if (!array_key_exists(self::SECTION, $manifest)) {
            return;
        }
        $section = $manifest[self::SECTION];
        if (!is_array($section) || $section === [] || array_is_list($section)) {
            throw new \RuntimeException(
                'wprism: ' . self::prefix($name) . ' is ' . self::render($section)
                . ' — the section is a non-empty OBJECT keyed by the declaration each record is evidence for '
                . '(spec/repo-format.md § v3.14)'
            );
        }
        $targets = array_map('strval', array_keys($section));
        sort($targets, SORT_STRING);
        foreach ($targets as $target) {
            self::assert_target($name, $manifest, $target);
            self::assert_record($name, $target, $section[$target]);
        }
    }

    /**
     * A target ADDRESSES a declaration this manifest actually makes.
     *
     * This is the whole load-bearing half, and the one thing the prose grep it
     * replaces could only approximate. `str_contains($notes, $optionName)` is
     * satisfied by a note that mentions an option in passing, by a note about
     * an option the manifest stopped declaring three releases ago, and by the
     * string appearing inside an unrelated word; it cannot be satisfied only by
     * a rationale that is ABOUT a live declaration, because free prose has no
     * addresses. A target does: its head is a top-level key this manifest
     * declares, so a record for a section that was deleted refuses at load, and
     * a record for a section that was never declared cannot be written at all.
     *
     * The head is checked and the tail is NOT. `options.wpcf7`,
     * `tables.acme_thing.pk` and `post_meta._acme_ref[0]` are all legal, and
     * this rule deliberately does not resolve the remainder against the
     * section's own contents: the sub-grammars differ per section, several
     * address positions inside a value rather than a key, and a resolver here
     * would be a second, drifting copy of fourteen field grammars. The head is
     * what makes the address falsifiable at the granularity that matters — the
     * declaration is either still here or it is not.
     *
     * @param array<string,mixed> $manifest
     */
    private static function assert_target(string $name, array $manifest, string $target): void {
        if ($target === '' || trim($target) !== $target) {
            throw new \RuntimeException(
                'wprism: ' . self::prefix($name) . ' declares the target ' . self::render($target)
                . ' — a target is a non-empty string with no leading or trailing whitespace, addressing the '
                . 'declaration the record is evidence for (spec/repo-format.md § v3.14)'
            );
        }
        $head = explode('.', $target, 2)[0];
        $head = explode('[', $head, 2)[0];
        if ($head === self::SECTION) {
            throw new \RuntimeException(
                'wprism: ' . self::prefix($name) . ' declares the target ' . self::render($target)
                . ' — a record may not be evidence for this section itself; evidence addresses a DECLARATION, '
                . 'and a circular record would be the unfalsifiable prose this section replaces '
                . '(spec/repo-format.md § v3.14)'
            );
        }
        if (!array_key_exists($head, $manifest)) {
            $declared = array_map('strval', array_keys($manifest));
            sort($declared, SORT_STRING);
            throw new \RuntimeException(
                'wprism: ' . self::prefix($name) . ' declares the target ' . self::render($target)
                . " but this manifest declares no top-level '$head' — a record addresses a declaration this "
                . 'manifest makes, which is what distinguishes it from a note that merely mentions one '
                . '(spec/repo-format.md § v3.14). This manifest declares: ' . implode(', ', $declared)
            );
        }
    }

    /**
     * One record: `evidence[]`, and optionally the `answered[]` questions.
     *
     * Both key sets are CLOSED, in both directions, for the reason § v3.3
     * states one level up for the manifest itself: an unrecognised member that
     * means nothing is indistinguishable from a deliberate one. A section whose
     * whole purpose is to be machine-checkable rationale cannot admit a member
     * no checker reads — that is `notes` again, one nesting level deeper.
     */
    private static function assert_record(string $name, string $target, mixed $record): void {
        $at = self::prefix($name) . "['" . $target . "']";
        if (!is_array($record) || $record === [] || array_is_list($record)) {
            throw new \RuntimeException(
                "wprism: $at is " . self::render($record) . ' — a record is a non-empty object '
                . '{"evidence": [...]}, optionally with {"answered": [...]} (spec/repo-format.md § v3.14)'
            );
        }
        self::assert_exact_keys($at, $record, self::RECORD_REQUIRED, self::RECORD_OPTIONAL);
        self::assert_rows("$at.evidence", $record['evidence'], self::EVIDENCE_KEYS);
        if (array_key_exists('answered', $record)) {
            self::assert_rows("$at.answered", $record['answered'], self::ANSWERED_KEYS);
        }
    }

    /**
     * A non-empty LIST of objects, each carrying exactly $keys, each value a
     * non-empty string.
     *
     * Empty is refused rather than treated as "no evidence": a record with an
     * empty `evidence[]` is an assertion that a declaration is founded, made by
     * a document that then declines to say on what — which is strictly worse
     * than declaring nothing, because it reads as coverage.
     *
     * @param list<string> $keys
     */
    private static function assert_rows(string $at, mixed $rows, array $keys): void {
        if (!is_array($rows) || $rows === [] || !array_is_list($rows)) {
            throw new \RuntimeException(
                "wprism: $at is " . self::render($rows) . ' — a non-empty LIST of {'
                . implode(', ', $keys) . '} objects is required (spec/repo-format.md § v3.14)'
            );
        }
        foreach ($rows as $i => $row) {
            $rowAt = "$at" . '[' . $i . ']';
            if (!is_array($row) || $row === [] || array_is_list($row)) {
                throw new \RuntimeException(
                    "wprism: $rowAt is " . self::render($row) . ' — a row is an object with exactly {'
                    . implode(', ', $keys) . '} (spec/repo-format.md § v3.14)'
                );
            }
            self::assert_exact_keys($rowAt, $row, $keys, []);
            foreach ($keys as $key) {
                $value = $row[$key];
                if (!is_string($value) || trim($value) === '') {
                    throw new \RuntimeException(
                        "wprism: $rowAt" . ".$key is " . self::render($value)
                        . ' — every member of a row is a non-empty string (spec/repo-format.md § v3.14)'
                    );
                }
            }
        }
    }

    /**
     * Closed in both directions, and the refusal names EVERY offending member
     * rather than the first — the property `assert_top_level_keys()` already
     * has one level up, for the same reason: an author who mistyped two
     * members should not pay two round trips to learn two facts the engine knew
     * at once, and a refusal whose content depends on PHP's key order is not a
     * refusal a harness can pin.
     *
     * @param array<string,mixed> $subject
     * @param list<string> $required
     * @param list<string> $optional
     */
    private static function assert_exact_keys(
        string $at,
        array $subject,
        array $required,
        array $optional
    ): void {
        $legal = array_merge($required, $optional);
        sort($legal, SORT_STRING);
        $present = array_map('strval', array_keys($subject));
        $unknown = array_values(array_diff($present, $legal));
        $missing = array_values(array_diff($required, $present));
        sort($unknown, SORT_STRING);
        sort($missing, SORT_STRING);
        if ($unknown !== []) {
            throw new \RuntimeException(
                "wprism: $at declares " . self::quoted($unknown) . ', which this section does not define — its members '
                . 'are exactly ' . self::quoted($legal) . ', closed in both directions so that a member no '
                . 'checker reads cannot masquerade as reviewed rationale (spec/repo-format.md § v3.14)'
            );
        }
        if ($missing !== []) {
            throw new \RuntimeException(
                "wprism: $at is missing " . self::quoted($missing) . ' — its members are exactly ' . self::quoted($legal)
                . ' (spec/repo-format.md § v3.14)'
            );
        }
    }

    /**
     * `manifest '<name>' section 'declaration_evidence'`, written once. The
     * `wprism: ` prefix is added at each throw rather than here, so that every
     * nested path (`…['options.wpcf7'].evidence[0].source`) carries it exactly
     * once no matter how deep the refusal is raised.
     */
    private static function prefix(string $name): string {
        return "manifest '$name' section '" . self::SECTION . "'";
    }

    /** @param list<string> $keys */
    private static function quoted(array $keys): string {
        return implode(', ', array_map(static fn(string $k): string => "'" . $k . "'", $keys));
    }

    /**
     * Rendered as JSON, not var_export: the declaration arrived as JSON, and
     * var_export of an array is multi-line — a refusal that spans lines is
     * unreadable in a WP-CLI error and unmatchable by the harnesses that pin
     * these strings. The same choice `assert_engine_features()` makes, for the
     * same reason (`AdapterContractGrammar.php:463-467`).
     */
    private static function render(mixed $value): string {
        $shown = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($shown) ? $shown : var_export($value, true);
    }
}
