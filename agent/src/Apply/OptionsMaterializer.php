<?php
namespace Duo;

require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Grammar/Tokens.php';
require_once __DIR__ . '/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../Kernel/OptionState.php';
require_once __DIR__ . '/../Kernel/StructuredValue.php';
require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Grammar/SubKeyGrammar.php';
require_once __DIR__ . '/../Delete/DeleteGuardEvaluator.php';
require_once __DIR__ . '/CacheInvalidationTransaction.php';
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
    private const MAX_OPTION_VALUE_BYTES = 16777216;
    private const MAX_AUTOLOAD_BYTES = 20;
    private bool $authoredTransaction = false;
    /** @var list<\Closure():void> */
    private array $nativeRollbackCallbacks = [];

    public function __construct(
        private readonly Policy $policy,
        private readonly Tokens $tokens,
        private readonly ApplyFieldMaterializer $fieldMaterializer
    ) {
    }

    public function begin_authored_transaction(): void {
        if ($this->authoredTransaction) {
            throw new \RuntimeException('duo: options materializer transaction participant was already active');
        }
        $this->authoredTransaction = true;
        $this->nativeRollbackCallbacks = [];
    }

    /** Clear rollback authority only after the database COMMIT returned successfully. */
    public function commit_authored_transaction(): void {
        if (!$this->authoredTransaction) {
            throw new \RuntimeException('duo: options materializer transaction participant is not active');
        }
        $this->nativeRollbackCallbacks = [];
    }

    /** Restore plugin process state and exact raw storage before database ROLLBACK. */
    public function rollback_authored_transaction(): void {
        if (!$this->authoredTransaction) {
            return;
        }
        $failure = null;
        foreach (array_reverse($this->nativeRollbackCallbacks) as $restore) {
            try {
                $restore();
            } catch (\Throwable $restoreFailure) {
                $failure ??= $restoreFailure;
            }
        }
        $this->nativeRollbackCallbacks = [];
        if ($failure !== null) {
            throw new \RuntimeException(
                'duo: native option transaction rollback could not restore exact storage/runtime state; recovery_required',
                0,
                $failure
            );
        }
    }

    public function end_authored_transaction(): void {
        $this->nativeRollbackCallbacks = [];
        $this->authoredTransaction = false;
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
            [$realName, $rule, $ruleSource] = $this->option_apply_target((string) $name, $allOptions);
            if ($record['state'] === 'deleted') {
                if (!$withDeletes) {
                    throw new \RuntimeException("duo: internal invariant: option tombstone '$name' reached apply without --with-deletes");
                }
                global $wpdb;
                CacheInvalidationTransaction::assert_local_option_cache('authored option deletion');
                Db::delete($wpdb->options, ['option_name' => $realName], null, 'apply delete authored option');
                CacheInvalidationTransaction::queue_option($realName, 'authored option deletion');
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
                // active_plugins/template/stylesheet (docs/code-half.md
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
                $this->apply_option_sub_keys($name, $v, $rule, $ruleSource, $autoload, $warnings);
                continue;
            }
            $this->fieldMaterializer->upsert_option(
                $name,
                $this->fieldMaterializer->option_wire_value($this->apply_value($name, $v, $rule)),
                $autoload
            );
        }
    }

    /** @return array{0:string,1:array,2:?string} canonical name -> target-local name + owning rule/provenance */
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
            $details = $this->policy->option_rule_details_for_option($name, $allOptions);
            $rule = $details['rule'] ?? null;
            $source = $details['source'] ?? null;
            if ($rule === null) {
                $rule = $this->dynamic_option_rule_for_name($name);
                $source = $rule === null ? null : 'dynamic_options';
            }
            return [$name, $rule ?? [], is_string($source) ? $source : null];
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
        return [$realName, $rule, is_string($realDetails['source'] ?? null) ? $realDetails['source'] : null];
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
        if (!empty($rule['plain_data'])) {
            return $this->tokens->plain_data_apply($v);
        }
        if (!empty($rule['ref'])) {
            // Options and metadata share the same ref/cast declaration.
            // The rule-aware codec is required here: PMPro's CSV level-order
            // options must return to comma-delimited strings because the
            // plugin passes them directly to explode(). The ref-only codec
            // materialized a serialized PHP array and made every subsequent
            // PMPro bootstrap fatal before Duo could retry or repair it.
            return $this->tokens->meta_tokens_to_value($v, $rule);
        }
        if (is_string($v)) {
            return $this->tokens->detokenize_text($v);
        }
        return $v;
    }

    /**
     * sub_keys apply (DUO-3233): SUB-KEY-LEVEL merge into the LIVE blob —
     * never a whole-value replace. Reads and locks the target's own CURRENT
     * value, overlays only declared-authored sub-keys, and preserves every
     * declared target-owned sibling. A manifest-owned interpreter may replace
     * that generic merge with a plugin-native grouped save, but only when the
     * complete effective rule and its provenance exactly match the declaring
     * manifest; an override may not borrow a digest-bound native hook merely
     * by repeating the same sub-key names.
     *
     * Absent-live-option case: starts the generic merge from an empty array
     * (warned) rather than refusing outright. A native materializer can
     * instead persist its complete registered default/env shape, so the
     * generic-only warning is deliberately deferred until after dispatch.
     * The ordinary case where this
     * would matter — Polylang/Yoast not yet activated on this target — is
     * caught upstream of this code path: spec/repo-format.md's code-half
     * ordering runs `wp duo deploy` (real activate_plugin() calls) before
     * `wp duo apply`, so the owning plugin's own activation-time
     * add_option() has normally already populated this option by the time
     * apply reaches here. A bare `apply` run in isolation (e.g. a test)
     * against a plugin that was never activated is a real, if unusual,
     * situation this still handles honestly rather than refusing: the
     * generic merged option ends up containing ONLY the declared sub-keys,
     * which is observable (warned) rather than silently incomplete.
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
     * The single indexed equality SELECT ... FOR UPDATE protects both the
     * existing row and, under the required InnoDB + REPEATABLE-READ boundary,
     * its absent-key gap. Without that proof a concurrent plugin/admin write
     * could be overwritten while the postcondition observed only our own
     * bytes and falsely blessed target-owned sibling loss.
     *
     * @param array<string,mixed> $rule complete effective rule, not a
     *   reconstructed static declaration
     * @param string[] $warnings appended to in place
     */
    private function apply_option_sub_keys(
        string $name,
        $captured,
        array $rule,
        ?string $ruleSource,
        string $autoload,
        array &$warnings
    ): void {
        global $wpdb;
        if (!is_array($captured)) {
            throw new \RuntimeException(
                "duo: captured option '$name' declares sub_keys but its repository value is not an object"
            );
        }
        $subKeys = (array) ($rule['sub_keys'] ?? []);
        CacheInvalidationTransaction::assert_local_option_cache(
            "mixed-option materialization for '$name'"
        );
        DeleteGuardEvaluator::assert_table_identifiers([$wpdb->options], 'mixed-option row locking');
        try {
            DeleteGuardEvaluator::assert_active_transaction('mixed-option row locking');
        } catch (\RuntimeException) {
            throw new \RuntimeException(
                "duo: mixed-option row locking for '$name' requires an active authored transaction"
            );
        }
        DeleteGuardEvaluator::assert_innodb_tables([$wpdb->options], 'mixed-option row locking');
        DeleteGuardEvaluator::assert_transaction_isolation('mixed-option row locking');
        $lockIndex = DeleteGuardEvaluator::full_width_lock_index(
            $wpdb->options,
            'option_name',
            'mixed-option row locking',
            true
        );
        $row = $this->lock_option_row($name, $lockIndex, 'mixed-option row locking');
        $raw = is_array($row) ? $row['option_value'] : null;
        $targetAutoload = is_array($row) ? $row['autoload'] : null;
        if ($raw === null) {
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
        SubKeyGrammar::assert_closed_value(
            $name,
            $rule,
            $live,
            'target'
        );
        $materialized = [];
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
            $materialized[(string) $subKey] = $this->apply_value("$name.$subKey", $subVal, $subRule);
        }
        $finalizeCalls = 0;
        $storageWriteCalls = 0;
        $finalizedRow = null;
        $runtimeRestore = null;
        $runtimeRestoreRegistrations = 0;
        $nativeStorageTouched = false;
        $rollbackArmed = false;
        $rolledBack = false;
        $writeStorage = function (array $value) use (
            $name,
            $autoload,
            $raw,
            $rule,
            &$runtimeRestoreRegistrations,
            &$storageWriteCalls,
            &$nativeStorageTouched
        ): void {
            global $wpdb;
            if (!$this->authoredTransaction || $runtimeRestoreRegistrations !== 1) {
                throw new \RuntimeException(
                    "duo: native option materializer for '$name' attempted storage before arming runtime rollback"
                );
            }
            ++$storageWriteCalls;
            if ($storageWriteCalls !== 1) {
                throw new \RuntimeException(
                    "duo: native option materializer for '$name' wrote storage more than once"
                );
            }
            PlainData::assert($value, "native materialized option '$name'");
            SubKeyGrammar::assert_closed_value($name, $rule, $value, 'native materialized target');
            $wire = $this->fieldMaterializer->option_wire_value($value);
            if (strlen($wire) > self::MAX_OPTION_VALUE_BYTES) {
                throw new \RuntimeException(
                    "duo: native option materializer for '$name' produced an oversized storage value"
                );
            }
            CacheInvalidationTransaction::assert_local_option_cache(
                "native option materializer for '$name'"
            );
            DeleteGuardEvaluator::assert_transaction_isolation(
                'native mixed-option engine-owned storage write'
            );
            if ($raw === null) {
                Db::insert(
                    $wpdb->options,
                    ['option_name' => $name, 'option_value' => $wire, 'autoload' => $autoload],
                    null,
                    'apply insert native-authored option'
                );
            } else {
                Db::update(
                    $wpdb->options,
                    ['option_value' => $wire, 'autoload' => $autoload],
                    ['option_name' => $name],
                    null,
                    null,
                    'apply update native-authored option'
                );
            }
            $nativeStorageTouched = true;
            CacheInvalidationTransaction::queue_option(
                $name,
                "native option materializer for '$name'"
            );
        };
        $finalizeStorage = function () use (
            $name,
            $lockIndex,
            &$storageWriteCalls,
            &$finalizeCalls,
            &$finalizedRow
        ): array {
            ++$finalizeCalls;
            if ($finalizeCalls !== 1) {
                throw new \RuntimeException(
                    "duo: native option materializer for '$name' finalized storage more than once"
                );
            }
            if ($storageWriteCalls !== 1) {
                throw new \RuntimeException(
                    "duo: native option materializer for '$name' finalized without exactly one engine-owned storage write"
                );
            }
            DeleteGuardEvaluator::assert_transaction_isolation(
                'native mixed-option storage finalization'
            );
            $row = $this->lock_option_row(
                $name,
                $lockIndex,
                'native mixed-option raw storage verification'
            );
            if ($row === null) {
                throw new \RuntimeException(
                    "duo: native option materializer for '$name' left no raw storage row"
                );
            }
            $finalizedRow = $row;
            return $row;
        };
        $restoreStorage = function () use ($name, $raw, $targetAutoload, $lockIndex): ?array {
            global $wpdb;
            DeleteGuardEvaluator::assert_transaction_isolation(
                'native mixed-option storage restoration'
            );
            $current = $this->lock_option_row(
                $name,
                $lockIndex,
                'native mixed-option storage restoration current-row lock'
            );
            if ($raw === null) {
                if ($current !== null) {
                    Db::delete(
                        $wpdb->options,
                        ['option_name' => $name],
                        null,
                        'restore absent native-authored option after failed apply'
                    );
                }
                CacheInvalidationTransaction::queue_option(
                    $name,
                    "native option materializer for '$name' restoration"
                );
                $restored = $this->lock_option_row(
                    $name,
                    $lockIndex,
                    'restored absent native mixed-option raw storage verification'
                );
                if ($restored !== null) {
                    throw new \RuntimeException(
                        "duo: native option materializer for '$name' could not restore exact absent storage"
                    );
                }
                return null;
            }
            if ($current === null) {
                Db::insert(
                    $wpdb->options,
                    [
                        'option_name' => $name,
                        'option_value' => (string) $raw,
                        'autoload' => (string) $targetAutoload,
                    ],
                    null,
                    'restore native-authored option after failed apply'
                );
            } else {
                Db::update(
                    $wpdb->options,
                    ['option_value' => (string) $raw, 'autoload' => (string) $targetAutoload],
                    ['option_name' => $name],
                    null,
                    null,
                    'restore native-authored option after failed apply'
                );
            }
            CacheInvalidationTransaction::queue_option(
                $name,
                "native option materializer for '$name' restoration"
            );
            $restored = $this->lock_option_row(
                $name,
                $lockIndex,
                'restored native mixed-option raw storage verification'
            );
            if ($restored === null
                || !hash_equals((string) $raw, $restored['option_value'])
                || !hash_equals((string) $targetAutoload, $restored['autoload'])) {
                throw new \RuntimeException(
                    "duo: native option materializer for '$name' could not restore exact storage"
                );
            }
            return $restored;
        };
        $companionWitnesses = [];
        $lockTargetOption = function (string $targetName) use (
            $name,
            $ruleSource,
            $lockIndex,
            &$companionWitnesses
        ): ?array {
            global $wpdb;
            if ($targetName === $name
                || preg_match('/^[A-Za-z0-9_.:-]{1,191}$/D', $targetName) !== 1) {
                $fingerprint = 'string:' . strlen($targetName) . ':'
                    . substr(hash('sha256', $targetName), 0, 16);
                throw new \RuntimeException(
                    "duo: native option materializer for '$name' requested an invalid companion lock "
                    . "($fingerprint)"
                );
            }
            $details = $this->policy->option_rule_details($targetName);
            $targetRule = $details['rule'] ?? null;
            if ($ruleSource === null
                || ($details['source'] ?? null) !== $ruleSource
                || !in_array(($targetRule['class'] ?? null), ['runtime', 'derived', 'env'], true)) {
                throw new \RuntimeException(
                    "duo: native option materializer for '$name' requested an undeclared/non-target-owned companion lock"
                );
            }
            $targetRow = $this->lock_option_row(
                $targetName,
                $lockIndex,
                "native option materializer for '$name' companion row locking"
            );
            if (array_key_exists($targetName, $companionWitnesses)
                && $companionWitnesses[$targetName] !== $targetRow) {
                throw new \RuntimeException(
                    "duo: native option materializer for '$name' changed a companion after locking it"
                );
            }
            $companionWitnesses[$targetName] = $targetRow;
            if ($targetRow === null) return null;
            return [
                'option_value' => $targetRow['option_value'],
                'autoload' => $targetRow['autoload'],
            ];
        };
        $registerRuntimeRestore = function (\Closure $restore) use (
            $name,
            &$runtimeRestore,
            &$runtimeRestoreRegistrations,
            &$rollbackArmed,
            &$rolledBack,
            &$nativeStorageTouched,
            $restoreStorage
        ): void {
            ++$runtimeRestoreRegistrations;
            if ($runtimeRestoreRegistrations !== 1) {
                throw new \RuntimeException(
                    "duo: native option materializer for '$name' registered runtime restoration more than once"
                );
            }
            $runtimeRestore = $restore;
            if (!$this->authoredTransaction) {
                throw new \RuntimeException(
                    "duo: native option materializer for '$name' ran outside the options transaction participant"
                );
            }
            $rollbackArmed = true;
            $this->nativeRollbackCallbacks[] = function () use (
                &$rolledBack,
                &$nativeStorageTouched,
                $restoreStorage,
                &$runtimeRestore,
                $name
            ): void {
                if ($rolledBack) {
                    return;
                }
                $rolledBack = true;
                if ($nativeStorageTouched) {
                    $restoreStorage();
                }
                if ($runtimeRestore instanceof \Closure) {
                    $runtimeRestore();
                }
                CacheInvalidationTransaction::queue_option(
                    $name,
                    "native option materializer for '$name' rollback"
                );
            };
        };
        $handledNatively = $this->policy->materialize_option_sub_keys_via_interpreter(
            $name,
            $materialized,
            $rule,
            $ruleSource,
            $autoload,
            $raw === null ? null : $live,
            $lockTargetOption,
            $finalizeStorage,
            $restoreStorage,
            $registerRuntimeRestore,
            $writeStorage
        );
        if ($handledNatively) {
            if (!$this->authoredTransaction || !$rollbackArmed) {
                throw new \RuntimeException(
                    "duo: native option materializer for '$name' ran outside the options transaction participant"
                );
            }
            if ($finalizeCalls !== 1 || !is_array($finalizedRow)) {
                throw new \RuntimeException(
                    "duo: native option materializer for '$name' returned success without exactly one storage finalization"
                );
            }
            if ($runtimeRestoreRegistrations !== 1 || !($runtimeRestore instanceof \Closure)) {
                throw new \RuntimeException(
                    "duo: native option materializer for '$name' returned success without one runtime restoration callback"
                );
            }
            // The hook can execute arbitrary plugin callbacks after its first
            // final read. Re-prove transaction continuity and exact storage
            // after the hook returns so COMMIT/restart or a post-finalize
            // rewrite cannot be blessed as native success.
            DeleteGuardEvaluator::assert_transaction_isolation(
                'native mixed-option post-hook verification'
            );
            foreach ($companionWitnesses as $companionName => $companionWitness) {
                $currentCompanion = $this->lock_option_row(
                    (string) $companionName,
                    $lockIndex,
                    "native option materializer for '$name' companion post-hook verification"
                );
                if ($currentCompanion !== $companionWitness) {
                    throw new \RuntimeException(
                        "duo: native option materializer for '$name' changed a locked companion option"
                    );
                }
            }
            $verifiedRow = $this->lock_option_row(
                $name,
                $lockIndex,
                'native mixed-option post-hook raw storage verification'
            );
            if ($verifiedRow === null
                || !hash_equals($finalizedRow['option_name'], $verifiedRow['option_name'])
                || !hash_equals($finalizedRow['option_value'], $verifiedRow['option_value'])
                || !hash_equals($finalizedRow['autoload'], $verifiedRow['autoload'])) {
                throw new \RuntimeException(
                    "duo: native option materializer for '$name' changed storage after finalization; recovery_required"
                );
            }
            $verifiedValue = PlainData::decode(
                $verifiedRow['option_value'],
                "native materialized option '$name'"
            );
            if (!is_array($verifiedValue)) {
                throw new \RuntimeException(
                    "duo: native option materializer for '$name' did not persist an array-shaped mixed option"
                );
            }
            SubKeyGrammar::assert_closed_value(
                $name,
                $rule,
                $verifiedValue,
                'native materialized target'
            );
            foreach ($materialized as $subKey => $desiredValue) {
                if (!array_key_exists($subKey, $verifiedValue)
                    || $verifiedValue[$subKey] !== $desiredValue) {
                    throw new \RuntimeException(
                        "duo: native option materializer for '$name' did not persist the exact authored group"
                    );
                }
            }
            foreach ($subKeys as $subKey => $subRule) {
                if (($subRule['class'] ?? null) === 'authored'
                    && !array_key_exists((string) $subKey, $materialized)
                    && array_key_exists((string) $subKey, $verifiedValue)) {
                    throw new \RuntimeException(
                        "duo: native option materializer for '$name' retained an absent authored sibling"
                    );
                }
                if (($subRule['class'] ?? null) === 'authored') {
                    continue;
                }
                $key = (string) $subKey;
                $wasPresent = array_key_exists($key, $live);
                $isPresent = array_key_exists($key, $verifiedValue);
                if ($wasPresent && (!$isPresent || $verifiedValue[$key] !== $live[$key])) {
                    throw new \RuntimeException(
                        "duo: native option materializer for '$name' changed a target-owned sibling"
                    );
                }
                if (!$wasPresent && $isPresent && ($subRule['native_default_completion'] ?? null) !== true) {
                    throw new \RuntimeException(
                        "duo: native option materializer for '$name' added a target-owned sibling without "
                        . 'native_default_completion authority'
                    );
                }
            }
            return;
        }
        if ($raw === null) {
            $warnings[] = "option $name: no live value to sub-key-merge into — creating it containing ONLY "
                . 'the declared sub-keys (its owning plugin\'s own defaults are absent; expected if that plugin '
                . 'has not been deployed/activated on this target yet)';
        }
        foreach ($materialized as $subKey => $subVal) {
            $live[$subKey] = $subVal;
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

    /** Native option APIs preserve an existing row's storage flag. */
    private function reconcile_native_option_autoload(string $name, string $autoload): void {
        global $wpdb;
        $current = $this->read_exact_native_autoload($name);
        if ($current !== $autoload) {
            Db::update(
                $wpdb->options,
                ['autoload' => $autoload],
                ['option_name' => $name],
                null,
                null,
                'apply update native-authored option autoload'
            );
            wp_cache_delete($name, 'options');
            wp_cache_delete('alloptions', 'options');
        }
        $stored = $this->read_exact_native_autoload($name);
        if (!hash_equals($autoload, $stored)) {
            throw new \RuntimeException(
                "duo: native option materializer for '$name' did not persist the canonical autoload value"
            );
        }
    }

    /**
     * Lock compact lengths before transferring LONGTEXT, then bind the full
     * row to that witness inside the same row/gap lock boundary.
     *
     * @return ?array{option_name:string,option_value:string,autoload:string}
     */
    private function lock_option_row(string $name, string $lockIndex, string $purpose): ?array {
        global $wpdb;
        DeleteGuardEvaluator::assert_transaction_isolation($purpose);
        $predicate = "FROM {$wpdb->options} FORCE INDEX (`$lockIndex`) WHERE option_name = %s "
            . 'ORDER BY option_id ASC LIMIT 2 FOR UPDATE';
        $wpdb->last_error = '';
        $sizes = $wpdb->get_results($wpdb->prepare(
            'SELECT option_name, OCTET_LENGTH(option_value) AS option_value_bytes, '
            . "OCTET_LENGTH(autoload) AS autoload_bytes $predicate",
            $name
        ), ARRAY_A);
        if (!is_array($sizes)
            || !array_is_list($sizes)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("duo: $purpose size preflight failed");
        }
        if (count($sizes) > 1) {
            throw new \RuntimeException("duo: $purpose found ambiguous collation-equal rows");
        }
        if ($sizes === []) return null;
        $size = $sizes[0];
        $valueBytes = is_array($size) ? self::canonical_size($size['option_value_bytes'] ?? null) : null;
        $autoloadBytes = is_array($size) ? self::canonical_size($size['autoload_bytes'] ?? null) : null;
        if (!is_array($size)
            || array_keys($size) !== ['option_name', 'option_value_bytes', 'autoload_bytes']
            || !is_string($size['option_name'] ?? null)
            || $valueBytes === null
            || $autoloadBytes === null
            || $valueBytes > self::MAX_OPTION_VALUE_BYTES
            || $autoloadBytes > self::MAX_AUTOLOAD_BYTES) {
            throw new \RuntimeException("duo: $purpose size preflight returned a malformed or oversized row");
        }
        if (!hash_equals($name, $size['option_name'])) {
            throw new \RuntimeException("duo: $purpose found a collation-equal option_name alias");
        }
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value, autoload $predicate",
            $name
        ), ARRAY_A);
        if (!is_array($rows)
            || !array_is_list($rows)
            || count($rows) !== 1
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("duo: $purpose value read failed after size preflight");
        }
        $row = $rows[0];
        if (!is_array($row)
            || array_keys($row) !== ['option_name', 'option_value', 'autoload']
            || !is_string($row['option_name'] ?? null)
            || !is_string($row['option_value'] ?? null)
            || !is_string($row['autoload'] ?? null)
            || !hash_equals($name, $row['option_name'])
            || strlen($row['option_value']) !== $valueBytes
            || strlen($row['autoload']) !== $autoloadBytes) {
            throw new \RuntimeException("duo: $purpose value read disagrees with the bounded size preflight");
        }
        return $row;
    }

    private function read_exact_native_autoload(string $name): string {
        global $wpdb;
        DeleteGuardEvaluator::assert_transaction_isolation(
            'native mixed-option autoload verification'
        );
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name = %s "
            . 'ORDER BY option_id ASC LIMIT 2',
            $name
        ), ARRAY_A);
        if (!is_array($rows)
            || !array_is_list($rows)
            || count($rows) !== 1
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException(
                "duo: native option materializer for '$name' did not leave one readable option row"
            );
        }
        $row = $rows[0];
        if (!is_array($row)
            || array_keys($row) !== ['option_name', 'autoload']
            || !is_string($row['option_name'] ?? null)
            || !is_string($row['autoload'] ?? null)
            || strlen($row['autoload']) > self::MAX_AUTOLOAD_BYTES
            || !hash_equals($name, $row['option_name'])) {
            throw new \RuntimeException(
                "duo: native option materializer for '$name' left a malformed/collation-aliased option row"
            );
        }
        return $row['autoload'];
    }

    private static function canonical_size(mixed $value): ?int {
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) return null;
        $size = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($size) && $size >= 0 ? $size : null;
    }
}
