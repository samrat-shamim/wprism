# Fixture staleness sweep

DUO-3274 audits the repository's gap-characterization fixtures against the
mechanisms present on `main` at `6bebb96e9c7ebed1c8eca91e141164a6a65444d4`.
The denominator is every `sandbox/tests/{regress_,certify_,grind_,spike_}*.sh`
or `.php` file at that revision: 103 regressions, 8 certifications, 8 grinds,
and 7 spikes, 126 files total. The three `cli_*smoke.sh` fixtures are outside
that naming denominator but were included in the assertion-safety audit.

Verdicts:

- `current`: the assertion describes the current contract.
- `prior-reconciled`: the file already asserts a landed mechanism instead of
  its historical gap.
- `aged-flip`: this sweep changed a stale expected limitation into a positive
  assertion for the landed mechanism.
- `aged-delete`: this sweep removed a workaround or retry whose premise no
  longer holds.
- `unclear-escalate`: the observation is real but its owner or mechanism is
  not established; it is linked to a dedicated Linear issue instead of being
  guessed at here.

## Gap-characterization ledger

| Script | Assertion under audit | Mechanism/evidence | Verdict |
| --- | --- | --- | --- |
| `grind_r1a_forms.sh` | Ninja Forms typed tables and block `formID` could not round-trip. | The shipped Ninja Forms typed-snapshot manifest and block-ref codec now pass the full fixture; DUO-3267 reconciled its setup and assertions. | `prior-reconciled` |
| `grind_r1a_forms.sh` | Large buffered render checks intermittently failed despite complete HTML. | The producer-side `echo "$VAR" \| grep -q` SIGPIPE race was already replaced with here-strings and self-diagnosing byte floors. | `prior-reconciled` |
| `grind_r1b_shop.sh` | Dynamic `pa_*` taxonomies were silently outside scope or unregistered on apply. | DUO-3229's scope gate, task #92 `taxonomy_patterns`, and the apply-side object-type fallback are asserted directly. | `prior-reconciled` |
| `grind_r1b_shop.sh` | The first apply may leave Duo Tee's `pa_size`/`pa_color` relationships absent and require a later content change. | The global apply pass now inserts all typed-table and term phase-1 rows before any post relationship phase. The fixture asserts exactly four relationships on the first apply and deletes the self-heal/content-change workaround. Full isolated live run passed. | `aged-flip`, `aged-delete` |
| `grind_r1b_shop.sh` | Whole-tree identity after environment-local stock/order operations was deterministic. | The run proved title/content identity, but independent runtime operations update `modified`/`modified_gmt` at different wall-clock times. The test now allows only those fields on Duo Tee and its variations; DUO-3302 owns the classification decision. | `unclear-escalate` |
| `grind_r1b_shop.sh` | Parent `is_purchasable` diagnoses relationship convergence. | Live evidence shows it can remain false with all four relationships present; it is retained as explicitly informational Woo caching output, never a Duo gap assertion. | `current` |
| `grind_r1c_agency.sh` | A real `elementor_active_kit` reference to an unscoped post was warned/dropped. | Task #73's exact ref gate and DUO-3229's coarser type gate now fail closed. The fixture classifies the coarse gate as runtime, then asserts the exact option-ref refusal. | `prior-reconciled` |
| `grind_r3a_multilingual.sh` | A fresh target required manual Polylang `post_types`/`taxonomies` configuration and a content-change retry before language relationships landed. | DUO-3280 supplements taxonomy object types from the compiled `polylang.post_types` sub-key during the same apply. Manual target config, retry, and forced content changes were removed; all product languages are asserted after the first apply. | `aged-flip`, `aged-delete` |
| `grind_r3a_multilingual.sh` | `pa_*` relationships may miss the first apply. | The same global typed-table/term phase-1 ordering as R1-B yields exactly four relationships on the unretried first apply. | `aged-flip`, `aged-delete` |
| `grind_r3a_multilingual.sh` | The dedicated German menu is only report-only on a fresh target. | DUO-3233 `sub_keys` captures/applies `polylang.nav_menus`; the healthy-front-end branch now hard-asserts `Hauptmenu`. | `aged-flip` |
| `grind_r3a_multilingual.sh` | Polylang language-term descriptions can become empty and make the front end call `set_locale(NULL)`. | Reproduced live on both source and target, including HTTP 500. No Duo apply had touched the source when observed. Root ownership remains unknown and is filed as DUO-3300. | `unclear-escalate` |
| `grind_r3a_multilingual.sh` | `pll_insert_post(... translations ...)` can omit the seed-time `post_translations` link. | This is an explicitly report-only Polylang write-path hazard already documented in DUO-3233; the merge-conflict assertion does not depend on the link. | `current` |
| `grind_r3b_events.sh` | TEC occurrences required a manual regeneration loop after apply. | DUO-3234 `regen_dependency` now regenerates occurrences in apply; the fixture asserts three query-visible events and zero pending markers. | `prior-reconciled` |
| `grind_r3b_events.sh` | PMPro composite restriction rows and `meta_id` sidecars could not round-trip. | DUO-3235 `identity.mode=composite_ref` and `id_column` are asserted directly with no repair. | `prior-reconciled` |
| `grind_r3b_events.sh` | TEC's aggregate `/events/list/` always becomes ready within the fixture's former retry budget. | Individual routes and database rows are reliable, but the aggregate view can lag under load after data is complete. No root cause is claimed; DUO-3301 owns the focused investigation. | `unclear-escalate` |
| `certify_deletion_matrix.sh` | WooCommerce product deletion could be forced after checking a known order-reference table. | DUO-3225 makes the product deletion capability explicitly unsupported because arbitrary extension references are not exhaustively representable. The certification now asserts that plan, apply, and forced apply all refuse before mutation. | `current` |
| `certify_deletion_matrix.sh` | A forced Ninja Forms parent deletion left guard-survivor rows as an unresolved finding requiring manual cleanup. | DUO-3251 retained the mandatory ref-integrity refusal, added exact survivor warnings, and shipped `wp duo orphans` delete/reparent recovery. The fixture now characterizes the refusal as a safety checkpoint and proves both supported recovery actions plus clean plan/capture. | `aged-flip` |
| `regress_woocommerce_contract.php`, `grind_r1b_shop.sh` | The DUO-3225 offline option inventory is complete for the exact WooCommerce 11.0.0 full-shop fixture. | A clean post-rebase R1-B run found five namespace-owned options with no exact classification. DUO-3303/PR #118 classified all five exact authored options plus the later `wc_pending_batch_processes` runtime row, extended the inventory regression, passed exact-commit Woo conformance, and completed the full R1-B blocker stack. | `prior-reconciled` |
| `regress_option_subkeys.sh` | The first apply leaves Polylang language relationships as drift and a retry/content change repairs them. | DUO-3280 is asserted on the single first apply; the no-op reapply must have zero drift. The later content update now proves ordinary preservation, not repair. | `prior-reconciled` |
| `regress_option_subkeys.sh` | Deep lint catches IDs below language-slug keys in `nav_menus`. | The script explicitly does not assert this; the existing blind spot is filed as DUO-3241. | `unclear-escalate` |
| `regress_option_ref_scope.sh` | In-scope-but-unminted references behave like ordinary dangling IDs. | The current script asserts the real scope-aware refusal/non-minting snapshot contract; reconciled in PR #51 (`926518c`). | `prior-reconciled` |
| `regress_coverage.sh` | Coverage may report unattributed or previously unknown plugin surfaces. | This is the feature's advisory output, and the regression asserts it never gates capture/apply. It is not an implementation-gap claim. | `current` |
| `regress_adapter_theme_range.sh` | A synthetic desired template that differs from the active stylesheet produces only a template-slot version finding. | Current deploy policy independently reports both the template's out-of-range version and the active stylesheet's parent-template mismatch. The fixture now asserts both findings instead of treating the newer invariant as noise. | `aged-flip` |
| `regress_snapshot_meta.sh` | A typed-table-only policy could leave stock posts/pages/categories silently outside scope, and its no-op apply had exactly two unchanged entities. | DUO-3229 requires explicit whole-surface dispositions, so the fixture records runtime exclusions for the stock core rows in both policy variants. The no-op assertion now checks the semantic contract (nonempty unchanged set, zero create/update/applied) instead of an incidental entity total. | `aged-flip` |
| `regress_woo_attribute_deletion.sh` | Woo global-attribute deletion is supported by generic row deletion. | The current contract is a deliberate loud refusal because semantic deletion is not representable; DUO-3288 records that boundary. | `current` |
| `regress_promotion.sh`, `regress_effect_bundle.php` | Mail/HTTP lifecycle observations can certify prevention. | The effect contract deliberately treats report-only observation as insufficient; prevention/restoration evidence is the asserted boundary. | `current` |
| `regress_manifest_dispositions.php`, `regress_multisite_refusal.sh` | Explicit unsupported surfaces are stale implementation gaps. | These are reviewed product boundaries with fail-closed assertions, not expected-to-fail placeholders. | `current` |

## Assertion-safety class

All executable instances of the following pattern were removed from the test
tree:

```bash
echo "$VALUE" | grep -q PATTERN
```

With `set -o pipefail`, a successful early `grep -q` can close the pipe while
`echo` is still writing, turning a successful match into status 141. The
replacement is a here-string (`grep -q PATTERN <<<"$VALUE"`), which has no
live producer process to race. This sweep replaced 182 such producer
pipelines across 30 scripts. The only remaining literal occurrence is the
explanatory comment in `grind_r1a_forms.sh`; non-quiet consumers such as
`grep -c` and `grep -o` remain pipelines because they consume the complete
input.

Every safety-only script was committed independently. Bash syntax and
`git diff --check` passed for every changed script.

## Exhaustive corpus index

For `current` entries below, the assertion under audit is the suite's stated
positive/refusal contract: the file contains no unresolved historical-gap
assertion after the focused term scan and mechanism trace. Candidate files
with more specific or mixed verdicts are detailed in the ledger above.

| Script | Assertion class | Verdict |
| --- | --- | --- |
| `certify_adversarial_matrix.sh` | Certification contract: adversarial matrix. No unresolved gap characterization. | `current` |
| `certify_deletion_matrix.sh` | Certification contract: deletion matrix. No unresolved gap characterization. | `current` |
| `certify_merge.sh` | Certification contract: merge. No unresolved gap characterization. | `current` |
| `certify_reference_bundle.sh` | Certification contract: reference bundle. No unresolved gap characterization. | `current` |
| `certify_ssh_adoption_roundtrip.sh` | Certification contract: ssh adoption roundtrip. No unresolved gap characterization. | `current` |
| `certify_ssh_rollback.sh` | Certification contract: ssh rollback. No unresolved gap characterization. | `current` |
| `certify_version_matrix.sh` | Certification contract: version matrix. No unresolved gap characterization. | `current` |
| `certify_version_skew_merge.sh` | Certification contract: version skew merge. No unresolved gap characterization. | `current` |
| `grind_code_half.sh` | Scenario contract: code half. No unresolved gap characterization. | `current` |
| `grind_code_half_ecosystem.sh` | Scenario contract: code half ecosystem. No unresolved gap characterization. | `current` |
| `grind_first_sync_hook_recovery.sh` | Scenario contract: first sync hook recovery. No unresolved gap characterization. | `current` |
| `grind_r1a_forms.sh` | Mechanism-specific historical assertions; see the gap ledger. | `prior-reconciled` |
| `grind_r1b_shop.sh` | Mechanism-specific historical assertions; see the gap ledger. | `aged-flip / aged-delete / unclear-escalate` |
| `grind_r1c_agency.sh` | Mechanism-specific historical assertions; see the gap ledger. | `prior-reconciled` |
| `grind_r3a_multilingual.sh` | Mechanism-specific historical assertions; see the gap ledger. | `aged-flip / aged-delete / unclear-escalate` |
| `grind_r3b_events.sh` | Mechanism-specific historical assertions; see the gap ledger. | `prior-reconciled / unclear-escalate` |
| `regress_acf_meta_interpreter.php` | Regression contract: acf meta interpreter. No unresolved gap characterization. | `current` |
| `regress_acf_meta_interpreter.sh` | Regression contract: acf meta interpreter. No unresolved gap characterization. | `current` |
| `regress_acf_term_options_fields.sh` | Regression contract: acf term options fields. No unresolved gap characterization. | `current` |
| `regress_adapter_contract.php` | Regression contract: adapter contract. No unresolved gap characterization. | `current` |
| `regress_adapter_contract.sh` | Regression contract: adapter contract. No unresolved gap characterization. | `current` |
| `regress_adapter_theme_range.sh` | Regression contract: adapter theme range. No unresolved gap characterization. | `current` |
| `regress_attachment_portability.sh` | Regression contract: attachment portability. No unresolved gap characterization. | `current` |
| `regress_block_refs.php` | Regression contract: block refs. No unresolved gap characterization. | `current` |
| `regress_block_refs.sh` | Regression contract: block refs. No unresolved gap characterization. | `current` |
| `regress_bundle_coverage.sh` | Regression contract: bundle coverage. No unresolved gap characterization. | `current` |
| `regress_capture_concurrency.sh` | Regression contract: capture concurrency. No unresolved gap characterization. | `current` |
| `regress_capture_publish.php` | Regression contract: capture publish. No unresolved gap characterization. | `current` |
| `regress_capture_publish.sh` | Regression contract: capture publish. No unresolved gap characterization. | `current` |
| `regress_capture_secret_scan.php` | Regression contract: capture secret scan. No unresolved gap characterization. | `current` |
| `regress_capture_secret_scan.sh` | Regression contract: capture secret scan. No unresolved gap characterization. | `current` |
| `regress_certification_bundle.php` | Regression contract: certification bundle. No unresolved gap characterization. | `current` |
| `regress_certification_bundle.sh` | Regression contract: certification bundle. No unresolved gap characterization. | `current` |
| `regress_checkpoint_bundle.php` | Regression contract: checkpoint bundle. No unresolved gap characterization. | `current` |
| `regress_classification_batch.php` | Regression contract: classification batch. No unresolved gap characterization. | `current` |
| `regress_code_compatibility.sh` | Regression contract: code compatibility. No unresolved gap characterization. | `current` |
| `regress_code_completed_unit.sh` | Regression contract: code completed unit. No unresolved gap characterization. | `current` |
| `regress_code_deploy_unit.sh` | Regression contract: code deploy unit. No unresolved gap characterization. | `current` |
| `regress_code_descriptor_unit.sh` | Regression contract: code descriptor unit. No unresolved gap characterization. | `current` |
| `regress_code_drift.sh` | Regression contract: code drift. No unresolved gap characterization. | `current` |
| `regress_code_ledger_transaction_unit.sh` | Regression contract: code ledger transaction unit. No unresolved gap characterization. | `current` |
| `regress_code_materializer_unit.sh` | Regression contract: code materializer unit. No unresolved gap characterization. | `current` |
| `regress_code_release.php` | Regression contract: code release. No unresolved gap characterization. | `current` |
| `regress_code_revision_enforcement.php` | Regression contract: code revision enforcement. No unresolved gap characterization. | `current` |
| `regress_code_stage_lock_unit.sh` | Regression contract: code stage lock unit. No unresolved gap characterization. | `current` |
| `regress_code_stage_transaction_unit.sh` | Regression contract: code stage transaction unit. No unresolved gap characterization. | `current` |
| `regress_collision.sh` | Regression contract: collision. No unresolved gap characterization. | `current` |
| `regress_composite_ref.php` | Regression contract: composite ref. No unresolved gap characterization. | `current` |
| `regress_composite_ref.sh` | Regression contract: composite ref. No unresolved gap characterization. | `current` |
| `regress_core_semantics.sh` | Regression contract: core semantics. No unresolved gap characterization. | `current` |
| `regress_coverage.sh` | Regression contract: coverage. No unresolved gap characterization. | `current` |
| `regress_coverage_offline.php` | Regression contract: coverage offline. No unresolved gap characterization. | `current` |
| `regress_discovery_completeness.sh` | Regression contract: discovery completeness. No unresolved gap characterization. | `current` |
| `regress_doctor_env_values.php` | Regression contract: doctor env values. No unresolved gap characterization. | `current` |
| `regress_dynamic_options_policy.php` | Regression contract: dynamic options policy. No unresolved gap characterization. | `current` |
| `regress_dynamic_options_policy.sh` | Regression contract: dynamic options policy. No unresolved gap characterization. | `current` |
| `regress_effect_bundle.php` | Regression contract: effect bundle. No unresolved gap characterization. | `current` |
| `regress_entity_type_width.sh` | Regression contract: entity type width. No unresolved gap characterization. | `current` |
| `regress_env_options_policy.php` | Regression contract: env options policy. No unresolved gap characterization. | `current` |
| `regress_env_options_policy.sh` | Regression contract: env options policy. No unresolved gap characterization. | `current` |
| `regress_env_set.sh` | Regression contract: env set. No unresolved gap characterization. | `current` |
| `regress_export_manifest_roundtrip.php` | Regression contract: export manifest roundtrip. No unresolved gap characterization. | `current` |
| `regress_export_manifest_roundtrip.sh` | Regression contract: export manifest roundtrip. No unresolved gap characterization. | `current` |
| `regress_fatal_mutations.php` | Regression contract: fatal mutations. No unresolved gap characterization. | `current` |
| `regress_fatal_mutations.sh` | Regression contract: fatal mutations. No unresolved gap characterization. | `current` |
| `regress_fatal_mutations_unit.sh` | Regression contract: fatal mutations unit. No unresolved gap characterization. | `current` |
| `regress_interpreter_policy.php` | Regression contract: interpreter policy. No unresolved gap characterization. | `current` |
| `regress_interpreter_policy.sh` | Regression contract: interpreter policy. No unresolved gap characterization. | `current` |
| `regress_journal_bootstrap.php` | Regression contract: journal bootstrap. No unresolved gap characterization. | `current` |
| `regress_lifecycle_options_snapshot.php` | Regression contract: lifecycle options snapshot. No unresolved gap characterization. | `current` |
| `regress_lifecycle_phase_handoff_unit.php` | Regression contract: lifecycle phase handoff unit. No unresolved gap characterization. | `current` |
| `regress_lifecycle_state_handoff.php` | Regression contract: lifecycle state handoff. No unresolved gap characterization. | `current` |
| `regress_manifest_dispositions.php` | Regression contract: manifest dispositions. No unresolved gap characterization. | `current` |
| `regress_manifest_dispositions.sh` | Regression contract: manifest dispositions. No unresolved gap characterization. | `current` |
| `regress_manifest_reclassification_policy.php` | Regression contract: manifest reclassification policy. No unresolved gap characterization. | `current` |
| `regress_manifest_reclassification_policy.sh` | Regression contract: manifest reclassification policy. No unresolved gap characterization. | `current` |
| `regress_menu_field_reclassification_policy.php` | Regression contract: menu field reclassification policy. No unresolved gap characterization. | `current` |
| `regress_menu_field_reclassification_policy.sh` | Regression contract: menu field reclassification policy. No unresolved gap characterization. | `current` |
| `regress_menu_item_meta_gate.sh` | Regression contract: menu item meta gate. No unresolved gap characterization. | `current` |
| `regress_multisite_refusal.sh` | Regression contract: multisite refusal. No unresolved gap characterization. | `current` |
| `regress_natural_key_rename.php` | Regression contract: natural key rename. No unresolved gap characterization. | `current` |
| `regress_option_name_refs_wiring.sh` | Regression contract: option name refs wiring. No unresolved gap characterization. | `current` |
| `regress_option_reconciliation.sh` | Regression contract: option reconciliation. No unresolved gap characterization. | `current` |
| `regress_option_ref_scope.sh` | Mechanism-specific historical assertions; see the gap ledger. | `prior-reconciled` |
| `regress_option_subkeys.sh` | Mechanism-specific historical assertions; see the gap ledger. | `prior-reconciled / unclear-escalate` |
| `regress_order_preserving.php` | Regression contract: order preserving. No unresolved gap characterization. | `current` |
| `regress_order_preserving.sh` | Regression contract: order preserving. No unresolved gap characterization. | `current` |
| `regress_pa_attributes.sh` | Regression contract: pa attributes. No unresolved gap characterization. | `current` |
| `regress_pair_bootstrap_unit.sh` | Regression contract: pair bootstrap unit. No unresolved gap characterization. | `current` |
| `regress_plan_summary_code_drift.php` | Regression contract: plan summary code drift. No unresolved gap characterization. | `current` |
| `regress_plugin_dependency_order.php` | Regression contract: plugin dependency order. No unresolved gap characterization. | `current` |
| `regress_pmpro_composite_ref.sh` | Regression contract: pmpro composite ref. No unresolved gap characterization. | `current` |
| `regress_promotion.sh` | Regression contract: promotion. No unresolved gap characterization. | `current` |
| `regress_promotion_lock.sh` | Regression contract: promotion lock. No unresolved gap characterization. | `current` |
| `regress_promotion_unit.sh` | Regression contract: promotion unit. No unresolved gap characterization. | `current` |
| `regress_recovery_executor.php` | Regression contract: recovery executor. No unresolved gap characterization. | `current` |
| `regress_regen_dependency_policy.php` | Regression contract: regen dependency policy. No unresolved gap characterization. | `current` |
| `regress_regen_dependency_policy.sh` | Regression contract: regen dependency policy. No unresolved gap characterization. | `current` |
| `regress_repository_authorization.sh` | Regression contract: repository authorization. No unresolved gap characterization. | `current` |
| `regress_repository_compiler.sh` | Regression contract: repository compiler. No unresolved gap characterization. | `current` |
| `regress_repository_compiler_integration.sh` | Regression contract: repository compiler integration. No unresolved gap characterization. | `current` |
| `regress_rollback_authority.php` | Regression contract: rollback authority. No unresolved gap characterization. | `current` |
| `regress_scope_gate.sh` | Regression contract: scope gate. No unresolved gap characterization. | `current` |
| `regress_shipping_zones.sh` | Regression contract: shipping zones. No unresolved gap characterization. | `current` |
| `regress_shortcode_refs.php` | Regression contract: shortcode refs. No unresolved gap characterization. | `current` |
| `regress_shortcode_refs.sh` | Regression contract: shortcode refs. No unresolved gap characterization. | `current` |
| `regress_snapshot_meta.sh` | Regression contract: snapshot meta. No unresolved gap characterization. | `current` |
| `regress_ssh_adopt.sh` | Regression contract: ssh adopt. No unresolved gap characterization. | `current` |
| `regress_ssh_rollback_certification.php` | Regression contract: ssh rollback certification. No unresolved gap characterization. | `current` |
| `regress_tec_regen.sh` | Regression contract: tec regen. No unresolved gap characterization. | `current` |
| `regress_template_mismatch.php` | Regression contract: template mismatch. No unresolved gap characterization. | `current` |
| `regress_term_meta.php` | Regression contract: term meta. No unresolved gap characterization. | `current` |
| `regress_upload_bundle.php` | Regression contract: upload bundle. No unresolved gap characterization. | `current` |
| `regress_url_query_refs.php` | Regression contract: url query refs. No unresolved gap characterization. | `current` |
| `regress_url_query_refs.sh` | Regression contract: url query refs. No unresolved gap characterization. | `current` |
| `regress_user_meta.sh` | Regression contract: user meta. No unresolved gap characterization. | `current` |
| `regress_widgets.sh` | Regression contract: widgets. No unresolved gap characterization. | `current` |
| `regress_woo_attribute_deletion.sh` | Regression contract: woo attribute deletion. No unresolved gap characterization. | `current` |
| `regress_woocommerce_contract.php` | The exact WooCommerce option-inventory gap found by the full-shop fixture was reconciled by DUO-3303/PR #118; see the gap ledger. | `prior-reconciled` |
| `spike_a_round_trip.sh` | Spike acceptance: a round trip. No unresolved gap characterization. | `current` |
| `spike_b_merge.sh` | Spike acceptance: b merge. No unresolved gap characterization. | `current` |
| `spike_c_provenance.sh` | Spike acceptance: c provenance. No unresolved gap characterization. | `current` |
| `spike_d_woo.sh` | Spike acceptance: d woo. No unresolved gap characterization. | `current` |
| `spike_e_acf.sh` | Spike acceptance: e acf. No unresolved gap characterization. | `current` |
| `spike_f_core_loop.sh` | Spike acceptance: f core loop. No unresolved gap characterization. | `current` |
| `spike_g_code.sh` | Spike acceptance: g code. No unresolved gap characterization. | `current` |

## Evidence

- `grind_r3a_multilingual.sh`: full isolated run passed. The first apply
  produced 4/4 `pa_*` relationships and all expected product languages with
  no manual target configuration or retry. The same run reproduced the
  separately filed DUO-3300 front-end failure.
- `grind_r1b_shop.sh`: the clean final blocker-stack run passed after
  DUO-3303/PR #118 and DUO-3304/PR #117. The first apply produced 4/4 `pa_*`
  relationships, the strict comparison reported only the two DUO-3302
  timestamp-bearing files while proving every other byte identical, and both
  environments converged on the editorially merged variation price.
- `certify_deletion_matrix.sh`: full clean-room run passed on the rebased
  `main`. Woo product deletion refused before mutation, Ninja Forms completed
  the supported `duo orphans` recovery path, and the PMPro composite delete
  settled idempotently. Its owned pair destroyed itself after the green run.
- `make regress-offline-all`: 59 offline suites green.
- All 30 changed shell scripts pass `bash -n`; the repository has no
  executable `echo "$VAR" | grep -q` assertion remaining.
