<?php
namespace Duo;

require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Grammar/Tokens.php';
require_once __DIR__ . '/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../Kernel/OptionState.php';
require_once __DIR__ . '/../Kernel/StructuredValue.php';
require_once __DIR__ . '/../Kernel/PlainData.php';
// Deliberately NOT require_once('Db.php') here: sandbox/tests/offline/reference-scope/regress_scoped_promotion_target.php
// stubs a fake Duo\Db and reaches this file transitively through Apply.php
// (a direct require of "$root/agent/src/Apply/Apply.php") without ever loading the
// real Db.php; requiring it here fatals that suite with "Cannot redeclare
// class Duo\Db" (caught by regress-offline-all while verifying this file) --
// the identical exclusion UserMetaMaterializer.php and TermMaterializer.php
// already document for the same reason. (regress_code_revision_enforcement.php
// also reaches this file transitively and stubs a fake class of its own, but
// it fakes Duo\Ledger, not Duo\Db -- irrelevant here since this file never
// references Ledger; verified via grep 'class (Db|Ledger)' against both
// suites individually rather than assumed, after an earlier slice in this
// same effort got exactly this kind of asymmetric-verification mistake wrong
// for a different class pair.)

/**
 * The options entity materializer (DUO-3347 slice 7, one of the "Entity
 * materializers: posts, terms, menus, options/meta/users, relationships,
 * attachments, typed tables" target seams): reconciles authored wp_options
 * rows -- plain, ref/json_refs/key_refs-tokenized, option_name_refs
 * token-form, and sub_keys mixed-ownership merges alike -- against one
 * target's live options table.
 *
 * Extracted from Apply.php on top of DUO-3347 slice 3's ApplyFieldMaterializer
 * (for the shared upsert_option()/option_wire_value() writes, called directly
 * here rather than through Apply's own now-thinner facades) -- apply_options()
 * and its five private helpers were otherwise fully self-contained: their only
 * external collaborators were Policy (option-name/rule resolution), Tokens
 * (ref/struct decoding), and the shared field writer. apply_options() had
 * exactly one caller in Apply.php (run()); every other method in this cluster
 * was called only from within the cluster itself.
 *
 * Scope: the one dependency outside the narrow contract. apply_option_sub_keys()
 * appends operator-visible diagnostics (an absent live option to sub-key-merge
 * into; a declared authored sub-key removed because capture no longer reports
 * it) to Apply's own $warnings collection -- a plain array appended to from
 * dozens of call sites across the whole of Apply.php and read once at the end
 * of run() to build the final plan/apply summary. That collection isn't a
 * narrow, injectable dependency the way Policy/Tokens/ApplyFieldMaterializer
 * are, so rather than back-reference Apply or duplicate the collection, both
 * apply_options() and apply_option_sub_keys() take it as an explicit
 * by-reference parameter instead -- the same array-output-parameter idiom
 * Apply.php's own find_collision()/collision_parent_id() already use
 * internally (array &$cache), extended here across the class boundary.
 *
 * Moved verbatim; Apply keeps apply_options() as a thin compatibility facade
 * via a lazily-constructed instance (options_materializer()), the same
 * pattern field_materializer()/menu_materializer()/user_meta_materializer()/
 * term_materializer() already established.
 */
final class OptionsMaterializer {
    public function __construct(
        private readonly Policy $policy,
        private readonly Tokens $tokens,
        private readonly ApplyFieldMaterializer $fieldMaterializer
    ) {
    }

    /**
     * @param string[] $warnings appended to in place (sub-key merge diagnostics)
     * @param ?array<string,mixed> $classificationDocument immutable complete
     *   source carrier for rule classification only; its unselected records
     *   are never iterated for writes.
     */
    public function apply_options(
        array $document,
        bool $withDeletes,
        array &$warnings,
        ?array $classificationDocument = null
    ): void {
        // DUO-3263: an interpreter-classified option (ACF's options-page
        // fields) needs the same document-sourced sibling map (the shadow
        // pointer) RepositoryAuthorization/RepositoryCompiler already build
        // from this same document (including valid v2 deletion witnesses) —
        // built once, reused per name below.
        $writeRecords = OptionState::records($document);
        $classificationDocument ??= $document;
        $classificationRecords = OptionState::records($classificationDocument);
        foreach ($writeRecords as $name => $record) {
            if (!isset($classificationRecords[$name])
                || !hash_equals(
                    OptionState::record_hash($record),
                    OptionState::record_hash($classificationRecords[$name])
                )) {
                throw new \RuntimeException(
                    "duo: option '$name' materialization has no identical immutable classification record"
                );
            }
        }
        $allOptions = OptionState::classification_values($classificationDocument);
        foreach ($writeRecords as $name => $record) {
            if ($record['state'] === 'absent') {
                continue; // explicit no-value/no-delete intent; target row is untouched
            }
            [$realName, $rule] = $this->option_apply_target((string) $name, $allOptions);
            if ($record['state'] === 'deleted') {
                if (!$withDeletes) {
                    throw new \RuntimeException("duo: internal invariant: option tombstone '$name' reached apply without --with-deletes");
                }
                global $wpdb;
                Db::delete($wpdb->options, ['option_name' => $realName], null, 'apply delete authored option');
                wp_cache_delete($realName, 'options');
                wp_cache_delete('alloptions', 'options');
                continue;
            }
            $v = $record['value'];
            $autoload = (string) $record['autoload'];
            OptionState::assert_rule_autoload($rule, $autoload, "repository option '$name'");
            // option_name_refs (task #93) — MUST run before the ordinary
            // option_rule($name) lookup below, unconditionally: a token-
            // form key like "woocommerce_flat_rate_{{wc_zone_method:...}}
            // _settings" matches no manifest's exact "options" map entry,
            // so option_rule() would return null -> an empty rule -> the
            // ordinary generic write path below, which would silently
            // upsert a REAL wp_options row whose NAME contains literal
            // "{{...}}" bytes — not a crash, a silent corruption of the
            // target's own options table. Detecting and detokenizing first
            // is what this task's own design review specifically flagged.
            if (str_contains($name, '{{')) {
                $vv = $this->tokens->struct_apply($v, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null);
                $this->fieldMaterializer->upsert_option($realName, $this->fieldMaterializer->option_wire_value($vv), $autoload);
                continue;
            }
            if (($rule['class'] ?? '') === 'managed') {
                // active_plugins/template/stylesheet (docs/proposals/code-half.md
                // §3.1): writing these via raw $wpdb would make WordPress believe
                // a plugin/theme is active while skipping every activation-hook
                // side effect that makes it actually work — activate_plugin()/
                // switch_theme() exist for exactly that reason. Deploy::run()
                // (`wp duo deploy`) is the ONLY place these are ever reconciled,
                // deliberately outside this canary-armed apply.
                continue;
            }
            if (!empty($rule['sub_keys'])) {
                // DUO-3233: SUB-KEY-LEVEL merge into the live blob, never a
                // whole-value replace — see apply_option_sub_keys()'s own
                // docblock for the full rationale.
                $this->apply_option_sub_keys($name, $v, $rule['sub_keys'], $autoload, $warnings);
                continue;
            }
            $this->fieldMaterializer->upsert_option(
                $name,
                $this->fieldMaterializer->option_wire_value($this->apply_value($name, $v, $rule)),
                $autoload
            );
        }
    }

    /** @return array{0:string,1:array} canonical name -> target-local name + owning rule */
    private function option_apply_target(string $name, array $allOptions): array {
        if (!str_contains($name, '{{')) {
            // Even a raw/noncanonical repository key must pass through the
            // option-name namespace validator. In particular, a leading-zero
            // would-be instance id must not fall through to the generic
            // option path and leave a stale target row behind.
            $this->policy->option_name_ref_match_details($name);
            // DUO-3263: interpreter-aware, not the plain static option_rule()
            // — an ACF options-page field (options_<name>/_options_<name>)
            // has no exact/pattern policy entry at all; only
            // meta_rule_for_option() consults the owning manifest's
            // interpreter. Caught live: the plain static lookup silently
            // returned [] here, and assert_rule_autoload() below correctly
            // refused to guess rather than writing an unclassified row.
            // meta_rule_for_option() itself already falls back to the same
            // static option_rule_details() lookup option_rule() uses when no
            // interpreter claims $name (Policy::rule_details_for_interpreter_hook()),
            // so it is a strict superset of the plain lookup, not a
            // replacement for it — DUO-3264's dynamic_options fallback
            // (theme_mods_<active theme>, disjoint namespace from every
            // ACF options-page name) chains after it for the same reason it
            // already chained after option_rule() before this merge.
            $rule = $this->policy->meta_rule_for_option($name, $allOptions) ?? $this->dynamic_option_rule_for_name($name);
            return [$name, $rule ?? []];
        }
        if (!preg_match('/\{\{([a-z][a-z0-9_]*):([0-9a-f-]{36})\}\}/', $name, $tm)) {
            throw new \RuntimeException("duo: option key '$name' contains '{{' but is not a well-formed ref token");
        }
        // Validate canonical ownership before resolving the token. This
        // rejects same-kind and cross-kind overlaps in the same way as live
        // capture and Snapshot preservation, rather than allowing a later
        // match to silently pick a different rule.
        $canonicalDetails = $this->policy->canonical_option_name_ref_details($name);
        if (($canonicalDetails['rule'] ?? null) === null) {
            throw new \RuntimeException(
                "duo: captured option key '$name' contains an identity token but no authored option_name_refs owner"
            );
        }
        $realId = $this->tokens->token_to_id($tm[0]);
        $realName = str_replace($tm[0], (string) $realId, $name);
        $realDetails = $this->policy->option_name_ref_match_details($realName);
        $rule = $realDetails['rule'] ?? null;
        if ($rule === null
            || ($rule['class'] ?? '') !== 'authored'
            || (string) ($rule['id_kind'] ?? '') !== (string) $tm[1]) {
            throw new \RuntimeException(
                "duo: captured option key '$name' looks token-form but matches no option_name_refs rule "
                . "after detokenizing to '$realName'"
            );
        }
        return [$realName, $rule];
    }

    /**
     * DUO-3264 (fork A): fall back to a dynamic_options-declared sub_keys
     * rule when the ordinary option_rule() lookup finds nothing —
     * theme_mods_<active stylesheet> is the proven case. Computes this
     * environment's own live resolver values (Policy.php stays WordPress-
     * free by design) and delegates the match itself to
     * Policy::dynamic_option_rule_for_name(), the same lookup
     * RepositoryAuthorization::authorize_options() also uses — one shared
     * place for "does this captured key match a declaration," not two.
     *
     * A captured document key only ever matches when it is EXACTLY the
     * name Policy::resolve_dynamic_option() would produce for THIS target
     * right now: deploy's own theme-reconciliation (DUO-3216) already
     * guarantees that equality holds by the time apply's own canary-armed
     * mutation phase runs (Apply::apply()'s refuse-gate hard-blocks a
     * theme mismatch before this method is ever reached) — the identical
     * invariant assign_locations()/nav_menu_locations already depends on,
     * not a new one.
     */
    private function dynamic_option_rule_for_name(string $name): ?array {
        return $this->policy->dynamic_option_rule_for_name($name, $this->dynamic_option_resolver_values());
    }

    /**
     * This environment's live value for every resolver the pinned manifests
     * actually declare.
     *
     * DUO-3318: the map used to be the single literal
     * `['active_stylesheet' => get_option('stylesheet')]`, which silently
     * answered "no value" for any OTHER declared resolver — and
     * Policy::dynamic_option_rule_for_name()'s matching `continue` then
     * turned that into an unclassified option instead of an error. Built
     * from the declarations instead, the map is complete by construction:
     * adding a resolver to SubKeyGrammar::DYNAMIC_OPTION_RESOLVERS without teaching
     * this match arm about it now fails loudly, at the first manifest that
     * declares it, naming the missing engine step.
     *
     * The match is deliberately a second copy of OptionsCapture::capture()'s,
     * not a shared helper: Policy.php is WordPress-free by design (it loads
     * in RepositoryCompiler's pure offline pass), so the one place that could
     * host a shared implementation is the one place that may not call
     * get_option(). Capture additionally honors a repository-supplied
     * override for the same resolver (a refresh export reads the captured
     * stylesheet, not this target's); apply has no such alternative source
     * because DUO-3216's theme refuse-gate has already proven the two agree.
     *
     * @return array<string,string>
     */
    private function dynamic_option_resolver_values(): array {
        $out = [];
        foreach ($this->policy->dynamic_options() as $key => $decl) {
            $resolver = (string) $decl['resolver'];
            $out[$resolver] = match ($resolver) {
                'active_stylesheet' => (string) get_option('stylesheet'),
                default => throw new \RuntimeException(
                    "duo: dynamic_options.$key declares unsupported resolver '$resolver'"
                ),
            };
        }
        return $out;
    }

    /**
     * Shared apply-direction dispatch, the mirror of OptionsCapture's value codec
     * — factored out for the identical reason: a sub_keys (DUO-3233) NAMED
     * sub-key's rule is a whole option rule at one nesting level down, so it
     * gets json_refs/key_refs/ref/plain-string detokenization for free, with
     * zero new dispatch logic to keep in sync with the ordinary per-option
     * path.
     */
    private function apply_value(string $ctx, $v, array $rule) {
        if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
            $v = $this->tokens->struct_apply($v, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null);
            return StructuredValue::encode($v, $rule, $ctx);
        }
        if (!empty($rule['ref'])) {
            return $this->tokens->tokens_to_value($v, $rule['ref']);
        }
        if (is_string($v)) {
            return $this->tokens->detokenize_text($v);
        }
        return $v;
    }

    /**
     * sub_keys apply (DUO-3233): SUB-KEY-LEVEL merge into the LIVE blob —
     * never a whole-value replace. Reads the target's own CURRENT value
     * (carrying every key this manifest did NOT carve out — Polylang's own
     * force_lang/rewrite/first_activation/version, populated by the
     * plugin's own activation-time add_option()/admin saves), overlays only
     * the captured, declared-authored sub-keys on top, and writes the
     * merged result back. The excluded remainder survives apply completely
     * untouched, on every environment, every run — this is the mechanism
     * manifests/polylang.json's own notes long documented as missing: "v0's
     * options model classifies a whole option name at once ... there is no
     * way to keep force_lang/default_lang/etc authored while excluding
     * first_activation/version without capturing them too."
     *
     * Absent-live-option case: starts the merge from an empty array
     * (warned) rather than refusing outright. The ordinary case where this
     * would matter — Polylang/Yoast not yet activated on this target — is
     * caught upstream of this code path: spec/repo-format.md's code-half
     * ordering runs `wp duo deploy` (real activate_plugin() calls) before
     * `wp duo apply`, so the owning plugin's own activation-time
     * add_option() has normally already populated this option by the time
     * apply reaches here. A bare `apply` run in isolation (e.g. a test)
     * against a plugin that was never activated is a real, if unusual,
     * situation this still handles honestly rather than refusing: the
     * merged option ends up containing ONLY the declared sub-keys, which is
     * observable (warned) rather than silently incomplete.
     *
     * Whole-option deletion remains ownership-exact and unrelated to the
     * tombstone below: DUO-3211's whole-row `deleted` record is authorized
     * only for a whole authored option, never for this mixed-ownership
     * shape, and represents an explicit, git-visible deletion INTENT with
     * its own record. This function draws a narrower, second distinction —
     * about one declared sub-key's own presence, not the containing
     * option's — covered next.
     *
     * Sub-key TOMBSTONE (DUO-3264): a `class: authored` sub-key that
     * $captured does not contain is REMOVED from the live blob below, not
     * left stale. Capture::capture_option_sub_keys() only ever omits a
     * declared-authored sub-key from its own output for reasons that are
     * ALL, unambiguously, "there is currently nothing valid to capture" —
     * confirmed by reading that method directly, not assumed: the live
     * blob's own key is genuinely absent (`!array_key_exists`), a ref value
     * is the WordPress "unset" convention of 0, or a ref id is dangling/
     * unscoped (warned or queued, never silently different from those two).
     * There is no capture-time reason a declared-authored sub-key goes
     * missing from $captured that means "still true, just not captured
     * this run" — so its absence here is as reliable a signal as its
     * presence, and the merge below finally treats it that way instead of
     * only ever adding/updating (its previous, asymmetric behavior, kept
     * for every OTHER key in $subKeys, is exactly why this fix is scoped to
     * $subKeys members only — see the loop below).
     *
     * Discovered live (DUO-3264 core conformance): theme_mods_<stylesheet>
     * 's own custom_logo/header_image_data.attachment_id sub-keys are
     * pointers to an attachment id, and WordPress itself deletes those SAME
     * theme_mods keys out of the live blob the instant the referenced
     * attachment is deleted (_delete_attachment_theme_mod(), core behavior,
     * not a Duo mechanism) — a completely ordinary action (an admin swaps
     * or removes a site logo). Before this fix, a target that had already
     * received the old value on an earlier apply kept serving the deleted
     * attachment's id forever; no later apply could ever remove what it
     * only ever knew how to add or overwrite. sub_keyed_options() (DUO-3233:
     * Polylang's force_lang/rewrite, Yoast's wpseo fields) shares this exact
     * code path and gets the identical fix, though its own declared
     * sub-keys have not been observed to disappear the way an attachment-
     * backed ref naturally can.
     *
     * Scoped strictly to sub-keys DECLARED `authored` in $subKeys: a
     * `runtime`/`derived`/`env`-classed declared sub-key (theme_mods' own
     * sidebars_widgets/wp_classic_sidebars, Polylang's first_activation/
     * version) is never captured in the first place and is never touched by
     * either loop below, regardless of $captured — removal must never
     * widen ownership beyond what capture actually owns, the same
     * invariant the original merge-only loop already upheld in the add/
     * update direction.
     *
     * @param string[] $warnings appended to in place
     */
    private function apply_option_sub_keys(string $name, $captured, array $subKeys, string $autoload, array &$warnings): void {
        global $wpdb;
        if (!is_array($captured)) {
            throw new \RuntimeException(
                "duo: captured option '$name' declares sub_keys but its repository value is not an object"
            );
        }
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name
        ));
        if ($raw === null) {
            $warnings[] = "option $name: no live value to sub-key-merge into — creating it containing ONLY "
                . 'the declared sub-keys (its owning plugin\'s own defaults are absent; expected if that plugin '
                . 'has not been deployed/activated on this target yet)';
            $live = [];
        } else {
            $live = PlainData::decode($raw, "live option '$name'");
            if (!is_array($live)) {
                throw new \RuntimeException(
                    "duo: live option '$name' is not array-shaped — cannot sub-key-merge into it (got "
                    . get_debug_type($live) . ')'
                );
            }
        }
        foreach ($captured as $subKey => $subVal) {
            $subRule = $subKeys[$subKey] ?? null;
            if (($subRule['class'] ?? '') !== 'authored') {
                // RepositoryAuthorization::authorize_option_sub_keys() already
                // refuses an undeclared/non-authored captured sub-key before
                // apply ever starts mutating anything — this is a defensive
                // invariant guard against that gate ever being bypassed
                // (e.g. a future internal caller of apply_options() that
                // skips the preflight), not a routinely-reachable branch.
                throw new \RuntimeException(
                    "duo: captured option '$name.$subKey' has no authored sub_keys rule — repository "
                    . 'authorization should have refused this before apply'
                );
            }
            $live[(string) $subKey] = $this->apply_value("$name.$subKey", $subVal, $subRule);
        }
        foreach ($subKeys as $subKey => $subRule) {
            if (($subRule['class'] ?? '') !== 'authored' || array_key_exists((string) $subKey, $captured)) {
                continue;
            }
            if (array_key_exists((string) $subKey, $live)) {
                unset($live[(string) $subKey]);
                $warnings[] = "option $name.$subKey: removed from the live blob — capture no longer reports "
                    . 'this declared authored sub-key (its own source value is gone on the captured environment, '
                    . 'e.g. a referenced attachment was deleted)';
            }
        }
        $this->fieldMaterializer->upsert_option($name, $this->fieldMaterializer->option_wire_value($live), $autoload);
    }
}
