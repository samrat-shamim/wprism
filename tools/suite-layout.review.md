# `tools/suite-layout.json` — the review record

The map is the authority for the `sandbox/tests` restructure; `tools/codemod/
move-suites.php` executes it and decides nothing. This file is the other half:
what each placement was decided *on*, which judgment calls a reviewer should
argue with, and the commands that prove the map's mechanical contracts.

**Scale.** 336 files at the corpus root. 335 are mapped; `offline_diagnostics_
guard.sh` is the one ratified stay (the `regress-offline-all` recipe invokes it
by name as the corpus wrapper). `lib/`, `fixtures/`, `support/` and the already-
nested `offline/guards/regress_suite_wiring.php` are untouched.

## How execution class was decided

Class is not a naming convention here; it is what the `Makefile` actually runs.

* **offline** — the transitive closure of `regress-offline-corpus`, taken from
  `make -n` rather than by reading the prerequisite list. 252 paths, which is
  exactly the count the `Makefile` asserts in its own
  `regress-offline-corpus: 252 offline suites green` line. 251 are at the root;
  the 252nd is `offline/guards/regress_suite_wiring.php`, already nested by W0.
* **live** — the 42 `regress-*` rows printed by `regress-live-list`. The list
  also names 5 `grind-*`/`certify-*` rows; those follow their prefix class, not
  the list.
* **grind / certify / spike** — by prefix, each confirmed to have a real
  `Makefile` target (`grind-r1a`…`grind-adoption`, `certify-merge`…
  `certify-ssh-rollback`, `spike-a`…`spike-e`).
* **helpers** — a file the `Makefile` never names, invoked by another suite.
  Placed with its consumer, per the rule below.

## Domain buckets

15 domains, seeded from the ratified design and then reviewed file by file
against each suite's own header and its `require` set. Sizes shifted from the
seed because the seed predates W1 and did not account for two families that the
evidence groups together (see *guards* and *pair* below). Every domain is at or
under 25 files.

| domain | n | what it means |
| --- | --- | --- |
| `adapter` | 22 | `agent/src/Adapter/*` — sources, catalog, registry, providers/actions, certification, the adapter-authoring draft path, shipped ecosystem adapter boundaries, and the production-readiness work ledger |
| `apply` | 17 | `agent/src/Apply/*` and `agent/src/Delete/*` — the DUO-3347 materializer/planner extractions, conflict/convergence, the checked-write boundary |
| `assess-contract` | 17 | the round-3 MUP vocabulary: `assess`, `contract`, `release`, `verify`, `recover`, `rehearse` |
| `capture` | 17 | `agent/src/Capture/*` and `agent/src/Publication/*` — the read side and its durable tree publication |
| `cli` | 24 | `cli/src/Command/*` verb surfaces, plan rendering, host-side onboarding/adoption |
| `code-half` | 25 | `agent/src/Code/*` plus the DUO-3350 Deploy/lifecycle collaborators |
| `ecommerce` | 7 | WooCommerce contracts and the ecommerce extension migration |
| `environment` | 11 | `Duo\Orchestrator\EnvironmentDriver` / DUO-3324 materialize-reap |
| `grammar` | 25 | `agent/src/Grammar/*` plus the Kernel classes that define how an authored *value* is represented (codecs, tokenizers, reference grammars, order preservation) |
| `guards` | 25 | suites whose subject is the estate or repo tooling, not shipped runtime behaviour |
| `policy` | 25 | `agent/src/Policy/*` — manifest load, validation, dispositions, reclassification, the manifest fixture pair |
| `recovery` | 11 | the `recovery/` runtime, rollback authority, control-plane/journal, host promotion state machine |
| `reference-scope` | 21 | `agent/src/Review/*` reference scanners plus the whole DUO-3344 scope/scoped-apply family |
| `refresh` | 7 | `Refresh`/`rebase` |
| `repository` | 18 | `agent/src/Repository/*` — compiler, parsers, validators, catalogs, snapshot identity, and the Kernel classes that define typed-table *shape* |

Two rules keep the two Kernel-heavy domains apart, because "it requires
`Kernel/`" describes most of the corpus and decides nothing:

* a Kernel class that says **how a value is represented** → `grammar`
  (`Canon`/`OrderPreserved`, `TextTokenizer`, `IdentityTokenCodec`,
  `StructuredReferenceCodec`, `UrlQueryReferenceCodec`,
  `ReferenceKindGrammar`, `ReferenceKeyspaceGrammar`);
* a Kernel class that says **what shape the typed tables have** → `repository`
  (`TableGraph`, `TableSchema`).

### What `guards` means

Consistently: **the thing under test is a file in the test estate, in repo
tooling, or a whole-tree invariant over product source** — never shipped
runtime behaviour. That single rule produces all 25 members and is why the
bucket is larger than the design's seed of 14; the seed did not separate out
the conformance-estate family or the pair family.

* estate/gate machinery — `regress_bundle_coverage.sh`,
  `regress_offline_diagnostics.sh`, `regress_close_gate_parent_count.sh`
  (subject: `scripts/close-gate-check.sh`)
* source hygiene over the product tree — `regress_agent_src_requires.php`
  ("every engine source file must name the engine classes it loads"),
  `regress_canonical_json_parity.php` (a cross-file byte-agreement invariant
  between the agent and its host twin). Both test *source*, not behaviour.
* conformance estate — `regress_conformance_asserts.sh`,
  `regress_observation_guards.sh`, `regress_target_observation_premises.sh`,
  `regress_explain_export_premise.sh`, `regress_explain_registry.sh`,
  `regress_polylang_fail_helper.sh`, `regress_elementor_dead_guard.sh`,
  `regress_elementor_matrix_reset.sh`
* pair/sandbox machinery — `regress_pair_bootstrap_unit.sh`,
  `regress_pair_budget_lock.sh`, `regress_pair_candidate_source.sh`,
  `regress_pair_compose_unit.sh`, `regress_proof_legacy_pair.sh`,
  `regress_fetch_artifact.sh`
* offline contracts *over a live or grind harness file* —
  `regress_live_exit_code_contract.sh`, `regress_scoped_apply_live_cleanup.php`,
  `regress_ssh_adopt_evidence_retention.php`,
  `regress_grind_r1c_manifest_preserve.sh`,
  `regress_ecommerce_developer_static.sh`, `regress_ecommerce_developer_matrix.sh`

A `pair` bucket would also have been defensible for the six sandbox-machinery
entries; it was not split out because it would have been the only domain under
the seed's smallest bucket and the "subject is the estate" rule already covers
them without a second name.

## Judgment calls

Ordered roughly by how much they deserve a second opinion.

**1. `manifest_fixtures.php` → `offline/policy/`, not `lib/`.** It self-
describes as "shared by every offline suite that needs a well-formed manifest",
which reads like substrate. It cannot go to `lib/`:
`move-suites.php:356` refuses any value under `lib/`/`fixtures/`/`support/`
("maps into shared substrate; substrate holds no suites"), and it cannot stay at
the root because the root is being emptied. So it went to the majority of its 10
`__DIR__` consumers — 6 in `policy`, 3 in `grammar`, 1 elsewhere. Three
`grammar` suites therefore acquire a `__DIR__ . '/../policy/manifest_fixtures.php'`
(listed under *Cross-directory references* below).

**2. `certification_fixture.php` → `offline/adapter/`.** Consumers span classes:
3 offline (`regress_adapter_sources.php`, `regress_adapter_sources.sh`,
`regress_init_contract.php`) and 2 live (`regress_duo_init.sh:269`,
`regress_local_bootstrap_live.sh:115`, both root-anchored so neither constrains
placement). Majority offline, and 2 of those 3 are the adapter-sources pair —
including a **bare `php -l certification_fixture.php` under a bare
`cd "$(dirname "$0")"`**, which is the only invocation form that genuinely wants
co-location. `regress_init_contract.php` pays a one-hop cross-directory require.

**3. `regress_manifest_validate.{sh,php}` → `policy`, not `adapter`.** The
command `duo manifest-validate` is an *adapter author's* tool, which argues for
`adapter`. Placed in `policy` anyway because the subject is
`Policy/ManifestValidator` and the manifest grammar document, its `-validator`
sibling (`regress_manifest_validator.php`) is in `policy`, and it requires
`manifest_fixtures.php`. Splitting `…_validate` from `…_validator` across two
directories would have been the more confusing outcome. `adapter/` keeps the
adapter *runtime* — sources, catalog, registry, providers, certification.

**4. `grind_ecommerce_developer.matrix.json` → `grind/`, against its consumers.**
This is the one place the "helpers follow their consumer" rule was overridden.
Measured: the matrix has exactly two readers,
`regress_ecommerce_developer_matrix.sh:11` and
`regress_ecommerce_developer_static.sh:9`, and **both are offline** —
`grind_ecommerce_developer.sh` never reads it (`grep -i matrix` on the harness
returns nothing). By the majority rule it would land in `offline/guards/` with
its two readers. It went to `grind/` because (a) the design ratified "grind data
files" as grind members, (b) it shares the stem `grind_ecommerce_developer` with
the harness it describes, and (c) neither reader constrains it — both reference
it by a root-anchored path (`sandbox/tests/…`, `tests/…`), so co-location buys
nothing. Flagging it because the stated rule and the outcome disagree.

**5. The `regress_fatal_mutations` cross-class stem.** `…_unit.sh` (offline)
does `cd "$(dirname "$0")"` then `php regress_fatal_mutations.php` — so the
`.php` is offline and must sit beside `…_unit.sh`. The `.sh` of the same stem is
live. Per the ratified design the live half renames:

```
sandbox/tests/regress_fatal_mutations.sh -> sandbox/tests/live/regress_fatal_mutations_live.sh
sandbox/tests/regress_fatal_mutations.php -> sandbox/tests/offline/apply/regress_fatal_mutations.php
```

**W2 must also rename the Makefile target** `regress-fatal-mutations` →
`regress-fatal-mutations-live` (`Makefile:288`, the `.PHONY` list at
`Makefile:31`, and the `regress-live-list` echo row), so target name stays
`==` basename stem. The codemod moves files; it does not rename targets.

**6. `regress_fatal_mutations.php` → `apply`.** Its subject is
`agent/src/Kernel/Db.php`'s checked-mutation abstraction ("treated wpdb false
like success, read insert_id zero into the ledger"). There is no `kernel`
domain; `repository` (the ledger) was the alternative. `apply` won because this
is the write boundary every materializer in that directory goes through, and its
live counterpart is a mutation-failure suite.

**7. `regress_code_release.php` → `recovery`, not `code-half`.** The name says
code; the class is `recovery/CodeRelease.php` and the suite loads
`recovery/rollback-control.php`. It sits with the other four `recovery/`
bundles (checkpoint, upload, effect, executor).

**8. The five root oddballs → `spike/`.** None is corpus-wired, so none
qualifies for `offline/guards/` under the "corpus-wired harness self-check"
rule. Evidence per file:

| file | Makefile target | needs | placed | why |
| --- | --- | --- | --- | --- |
| `cli_smoke.sh` | `cli-smoke` (`:95`) | spike-E pair e1/e2, profile `spikee` | `spike/` | its fixture *is* spike E — "`make spike-e` … boots it"; its target sits in the spike block |
| `cli_triage_smoke.sh` | `cli-triage-smoke` (`:98`) | spike-E pair, e1 only | `spike/` | "Companion to `cli_smoke.sh`" |
| `lint_smoke.sh` | `lint-smoke` (`:101`) | spike-E pair e1 | `spike/` | "prerequisite: `make spike-e` has been run at least once" |
| `cli_status_truth.sh` | **none** | own disposable pair | `spike/` | "run it by hand" — no target, deliberately |
| `check_guide_commands.sh` | **none** | nothing (pure source scan) | `spike/` | its own header: "Deliberately NOT named `regress_*` and deliberately has no Makefile target: **same precedent as `cli_status_truth.sh`**" |

`check_guide_commands.sh` is the uncomfortable one — it is hermetic, so
"offline" describes it, while `spike/` describes the docker smokes around it.
It is placed by the kinship its own header asserts: `spike/` is the hand-run,
non-gate family, and `offline/` is reserved for what the merge gate executes.
Renaming the bucket (`smoke/`, `manual/`) would describe all ten members better
than `spike/` does; that is a design question, not a mapping one.

`check_guide_commands.sh` is also named by absolute path in
`tools/offline.php:916,920` (`--extras`). `tools/` is inside the codemod's
`MS_SCAN_ROOTS`, so class 6 rewrites it.

**9. `regress_scope_command.php` → `cli`, away from the rest of DUO-3344.**
Every other scope suite is in `reference-scope`. This one loads
`cli/src/Command/ScopeCommand.php` and is the same argv-pinning shape as the
eleven other `regress_*_command.php` suites in `cli`. Consistency with the
`*_command.php` family beat consistency with the issue number.

**10. `regress_coverage_offline.php` → `assess-contract`, not
`reference-scope`.** The class is `agent/src/Review/Coverage.php` and
`reference-scope` is where the other `Review/*` suites went — but it also loads
`cli/src/Assess/SurfaceCatalog.php`, and what it tests is "what does this site
have that policy does not cover", which is the assess question, not reference
hygiene.

### Smaller calls, recorded without argument

* `regress_post_field_classification.php` → `grammar`, not `ecommerce`: it uses
  WooCommerce as the illustration but states it proves "the generic field
  contract".
* `regress_duo3316_contract.php` → `grammar`, beside
  `regress_taxonomy_object_keyspace.php` (same issue, same `object_keyspace`
  subject).
* `regress_bound_helper.php` → `capture`: `Publication\BoundHelper`, and
  `capture` is where the rest of `agent/src/Publication/*` went.
* `regress_journal_bootstrap.php` → `recovery`: `\Duo\Journal` is the
  fatal-safe control-plane record.
* `regress_frozen_materialization_promotion.php` → `environment`: confirmed by
  its requires (`Duo\Orchestrator\EnvironmentDriver`, `DriverCapability`).
* `regress_composite_ref.php` → `repository`: an identity mode on
  `Repository/Snapshot.php`, beside `regress_snapshot_identity.php` and
  `regress_natural_key_rename.php`.
* `regress_promotion_unit.sh` → `recovery` (host promotion state machine); the
  DUO-3344 *scoped* promotion suites go to `reference-scope` with their family.
* `regress_deletion_capability_resolver.php` → `policy`
  (`agent/src/Policy/DeletionCapabilityResolver.php`), not `apply` with the
  `Delete/` suites — it resolves a manifest declaration, it does not delete.
* Adoption/onboarding (`regress_adopt_rollback.php`,
  `regress_local_bootstrap.php`, `regress_classification_batch.php`,
  `regress_doctor_env_values.php`) → `cli`: all host-side
  `cli/src/Onboarding/*`, and no `onboarding` domain exists in the ratified set.

## Co-location constraints honored

**Bare cwd-relative invocations.** Twelve wrappers do `cd "$(dirname "$0")"`
and then invoke a sibling by bare name. Under the codemod's class-3 policy a
bare anchor is left byte-identical, so these only stay correct if the helper
moves into the *same* directory. All twelve do (asserted by check 10 below):

| invoker | bare-name helpers it runs | directory |
| --- | --- | --- |
| `regress_actions_providers.sh` | `regress_actions_providers.php`, `regress_provider_contract.php` | `offline/adapter` |
| `regress_adapter_sources.sh` | `regress_adapter_sources.php`, `certification_fixture.php` | `offline/adapter` |
| `regress_adapter_catalog.sh` | `regress_adapter_catalog.php` | `offline/adapter` |
| `regress_adapter_draft.sh` | `regress_adapter_draft.php` | `offline/adapter` |
| `regress_adapter_observation.sh` | `regress_adapter_observation.php` | `offline/adapter` |
| `regress_plugin_adapter_source.sh` | `regress_plugin_adapter_source.php` | `offline/adapter` |
| `regress_manifest_validate.sh` | `regress_manifest_validate.php`, `manifest_fixtures.php` | `offline/policy` |
| `regress_capture_secret_scan.sh` | `regress_capture_secret_scan.php` | `offline/capture` |
| `regress_scope_contract.sh` | `regress_scope_contract.php` | `offline/reference-scope` |
| `regress_shortcode_refs.sh` | `regress_shortcode_refs.php`, `regress_block_refs.php` | `offline/reference-scope` |
| `regress_url_query_refs.sh` | `regress_url_query_refs.php`, `regress_block_refs.php`, `regress_shortcode_refs.php` | `offline/reference-scope` |
| `regress_fatal_mutations_unit.sh` | `regress_fatal_mutations.php` | `offline/apply` |

The block / shortcode / url_query cross-check the brief called out is satisfied:
`regress_block_refs.php`, `regress_shortcode_refs.{sh,php}` and
`regress_url_query_refs.{sh,php}` are all five in `offline/reference-scope`, so
every one of those relative invocations stays a one-directory reference.

**Cross-directory `__DIR__` references the move creates.** Exactly five, all
resolvable by the codemod's class 2 (which follows the map):

```
regress_init_contract.php            __DIR__/regress_duo_init.sh        offline/cli -> live
regress_init_contract.php            __DIR__/certification_fixture.php  offline/cli -> offline/adapter
regress_option_reference_grammar.php __DIR__/manifest_fixtures.php      offline/grammar -> offline/policy
regress_reference_keyspace_grammar.php __DIR__/manifest_fixtures.php    offline/grammar -> offline/policy
regress_reference_kind_grammar.php   __DIR__/manifest_fixtures.php      offline/grammar -> offline/policy
```

**Root-anchored cross-class references.** These never constrained placement, but
each is a string W2/the codemod must rewrite. Non-exhaustive but complete for
the moved set as far as `rg` found it:

* `regress_duo_init.sh:269`, `regress_local_bootstrap_live.sh:115` →
  `php sandbox/tests/certification_fixture.php` (live → `offline/adapter`)
* `regress_lifecycle_planner.php:111` → `$root . '/sandbox/tests/regress_manifest_validate.sh'`
  (`code-half` → `policy`)
* `regress_scoped_apply_live_cleanup.php:19` → `…/regress_scoped_apply_live.sh`
  (`guards` → `live`)
* `regress_ssh_adopt_evidence_retention.php:19` → `…/regress_ssh_adopt.sh`
  (`guards` → `live`)
* `regress_live_exit_code_contract.sh:20-22` → `tests/regress_scope_gate.sh`,
  `tests/regress_repository_compiler_integration.sh`,
  `tests/regress_repository_authorization.sh` (`guards` → `live`)
* `regress_ecommerce_developer_static.sh:7,9` → `tests/grind_ecommerce_developer.sh`,
  `tests/grind_ecommerce_developer.matrix.json`;
  `regress_ecommerce_developer_matrix.sh:11-12` → the same two, repo-anchored
  (`guards` → `grind`)
* `regress_conformance_asserts.sh:41` → the literal
  `'^tests/regress_conformance_asserts.sh$'` — a *self*-reference inside a
  `grep -v`
* `regress_pair_bootstrap_unit.sh:1971`, `regress_pair_candidate_source.sh:650`
  → `"$ROOT/sandbox/tests/regress_pair_*.sh"` self-references
* `tools/offline.php:916,920` → `sandbox/tests/check_guide_commands.sh`
  (→ `spike/`)

### Two hazards worth naming for W2

* **`regress_init_contract.php:994` asserts on a path *string*, not a path.** It
  checks that `regress_duo_init.sh` contains the literal
  `php sandbox/tests/certification_fixture.php "$HERMETIC_ROOT"`. The assertion
  and the line it asserts about live in two different files that move to two
  different directories, so they must be rewritten *consistently* or the suite
  goes red on a correct move. Class 6 rewrites literal mentions of a mapped old
  path, which covers both — worth confirming in `--prove`.
* **`lint_smoke.sh:20` is `cd "$(dirname "$0")/.."` — a *named-directory* claim
  on `sandbox/`,** and everything below it is `sandbox/`-relative
  (`siterepo/e1/…`, `tmp/…`, `docker-compose.yml`). Moving it one level deeper
  requires the run to be extended to `/../..`, which is exactly the codemod's
  class-3 policy for a `/..`-carrying anchor. `regress_ecommerce_developer_
  static.sh` and `grind_ecommerce_developer.sh` have the same shape.

`regress_ssh_adopt_evidence_retention.php:83` asserts a harness does *not*
contain `certification_fixture.php`. That is a basename test and basenames do
not change, so it survives the move untouched.

## Validation

Run from the repo root. Every claim below is machine-checked, not eyeballed.

```
$ php <validator>            # scratch script, not committed
ok:   keys are sorted (SORT_STRING)
ok:   trailing newline after the closing brace
ok:   4-space indent
ok:   single flat object, one entry per line
ok:   335 keys all exist on disk
ok:   all values unique
ok:   no value collides with an existing path
ok:   all 335 value basenames unique
ok:   lib/, fixtures/, support/, offline_diagnostics_guard.sh and
      offline/guards/regress_suite_wiring.php are untouched
ok:   no value lands inside lib/, fixtures/ or support/
ok:   every root file is mapped except offline_diagnostics_guard.sh (the ratified stay)
ok:   11 same-stem pairs each land in one directory
ok:   the one cross-class stem (regress_fatal_mutations) is resolved by the ratified _live rename
ok:   251 offline-corpus suites all land under offline/
ok:   266 offline/ values = 251 corpus suites + 15 helpers
ok:   42 live/ values == the regress-live-list primaries (with the fatal-mutations rename)
ok:   11 values in grind/, all grind_*
ok:   6 values in certify/, all certify_*
ok:   12 bare cwd-relative invokers keep every helper in their own directory
ok:   15 offline domains, each <= 30 files (max 25); live/ is flat by design at 42

ALL CHECKS PASSED
```

What each non-obvious check does:

* **key-exists** — `is_file()` on every one of the 335 keys.
* **value uniqueness / no pre-existing value** — no two keys claim one
  destination, and no destination already exists on disk.
* **basename uniqueness** — 335 distinct basenames. This is the property
  `regress_bundle_coverage.sh`'s own collision check enforces (a target name
  comes from the basename alone, so two files sharing one leave the loser
  unrunnable while looking wired). The `regress_fatal_mutations` rename is what
  makes it hold; without it the map has exactly one collision.
* **same-stem → same directory** — computed on the *new* basenames, which is
  the property that matters. A second check asserts the only stem shared across
  two execution classes in the *old* names is `regress_fatal_mutations`.
* **offline class correctness** — the corpus is re-derived with
  `make -n regress-offline-corpus | grep -oE 'sandbox/tests/…' | sort -u`
  (252 paths) and every mapped member is asserted to land under `offline/`. The
  converse is asserted too: every `offline/` value is either a corpus member or
  one of the 15 named helpers.
* **live class correctness** — `make regress-live-list` is parsed for its
  `regress-*` rows, `_`→`-` is applied to each `live/` basename, and the two
  sets are compared for equality after substituting the ratified
  `regress-fatal-mutations-live`.

## Out of scope for this map

The codemod moves files and rewrites path expressions. It does **not** rename
`Makefile` targets. W2 owes exactly one:
`regress-fatal-mutations` → `regress-fatal-mutations-live`.

## Amendments after ratification

The map and this record answer two different questions, and they diverge the
moment a mapped file stops existing. `tools/suite-layout.json` is **executable**
— `ms_load_map()` calls `is_file()` on every row and refuses the whole map if
one names nothing (`"exists at neither its old nor its new path"`), which takes
`--plan`, `--apply` and `--prove` down together — so it must describe the tree
as it is. This document is the **decision record at decision time**, so a
placement that was correctly reviewed stays written down here even after the
file it placed is gone. Rows leave the map; they do not leave this record.

### 2026-08-20 — the two `reference-scope` wrappers (DUO-3482)

`#486` deleted `regress_shortcode_refs.sh` and `regress_url_query_refs.sh` after
W3 had already moved them: the two wrappers carried no logic beyond a `php -l`
pass and a re-run of siblings that are independently wired offline leaves, so
their recipes now run the `.php` directly under unchanged target names. Their
rows were left behind in the executable map, and because `.gitignore`-style
exclusion keeps the codemod from ever rewriting its own input
(`MS_SCAN_EXCLUDE`), nothing swept them. The effect was not cosmetic: from `#486`
until this note, `--prove` — the only pre-merge check that reaches the `live/`,
`grind/`, `certify/` and `spike/` files — refused before doing any work.

Both rows are now deleted from `tools/suite-layout.json` (335 → 333 entries).
The bare-name invoker table above still lists both wrappers at :255-256, and the
cross-check paragraph below it still says all five `reference-scope` files
co-locate: **both statements remain true of the moves they describe**, which is
what this record is for. The two `.php` files they name are still mapped and
still live in `offline/reference-scope/`.

The absent guard was the real defect, and the two stale rows were only its first
symptom, so `MoveSuitesTest::testTheShippedLayoutMapStillPlansCleanAgainstTheRealTree`
now runs `--plan --map=tools/suite-layout.json` against this checkout inside
`composer check`. The next suite deletion that strands a row fails the loop
immediately instead of silently disarming the prover for days.

### 2026-08-20 — the offline count is the `Makefile`'s to assert, and it has moved

"How execution class was decided" above says the offline closure is **252 paths,
which is exactly the count the `Makefile` asserts**, and the mechanical-contract
section re-derives the same 252 with `make -n regress-offline-corpus`. Both
sentences were true at ratification and stay written down. The number is not:
re-derived against this checkout, `make -n regress-offline-corpus | grep -oE
'sandbox/tests/…' | sort -u | wc -l` prints **254**, and the `Makefile`'s own
`regress-offline-corpus: 254 offline suites green` line agrees.

Nothing here is a defect, because 252 was never this document's number to own.
`regress_bundle_coverage.sh` expands the prerequisite graph and compares it
against that `Makefile` line (AGENTS.md non-negotiable 4), so the count of record
lives there and moves every time a suite is wired in — twice since ratification.
This record's claim is the *equality*: the closure and the asserted count are the
same set, re-derived the same way. That still holds. Quote `make -n`, or the
`Makefile` line, never a number transcribed into prose here.

The "251 at the root / the 252nd already nested" split is likewise decision-time
detail: the restructure this record planned has landed, so every offline member
now sits under `offline/<domain>/` and the root holds no suites at all
(`regress_suite_wiring.php` clause 4 refuses one that does).

### 2026-08-21 — the four code-half split suites (DUO-3499)

`regress_code_source_lock.php`, `regress_code_lock_compile_gate.php`,
`regress_init_code_split.php` and `regress_code_classify.php` all landed in
`code-half`, taking it from 21 to 25 — at the stated cap, not over it.

The placement is by SUBJECT, per this document's own rule. All four are about
the code half's own contract: what `code/duo-code.lock.json` may say, what
compilation refuses when the bytes disagree with it, and the two verbs that
write it. Two of them touch `agent/src/Init/*` and `cli/src/Command/*` and so
have a plausible case for `capture` (17) or `cli` (24) instead, and that case
was considered and rejected: `regress_init_code_split.php` asserts almost
nothing about init's transaction and almost everything about whether a
component may be declared out of Git, and `regress_code_classify.php` asserts
that a migration leaves `code_revision` byte-identical, which is a statement
about the descriptor. A reader looking for "why did my locked component refuse
to compile" reads `code-half`, and all four answers should be in one place.

`code-half` is now full. The next code-half suite either replaces one of these
or forces the split of this domain, which is what the cap is for.
