# Module map for `agent/src` and `cli/src`

The flat layout is gone: `agent/src` becomes 17 module directories, `cli/src` becomes 12.
The machine-readable map is [`tools/modules.json`](../../tools/modules.json); this page is its index and its rules.
Each module has a one-page charter next to this file.

**Namespaces do not change in this move.** `agent/src` stays `namespace Duo;` and `cli/src` stays
`namespace Duo\Orchestrator;`. Manifest interpreters, providers and regenerators name `\Duo\Policy`,
`\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN, and `ArtifactPolicyIdentity::manifest_rows()`
folds `hash_file('sha256', …)` of each of those hook files into the adapter's identity row
(`agent/src/Policy/ArtifactPolicyIdentity.php:74`, `:92`, `:115`), so a namespace change rewrites hook bytes
and moves every `adapter_digest` with them. Moving or renaming an `agent/src` class file costs nothing by
itself: the row folds manifest JSON bytes, disposition bytes and those hook hashes, and nothing else.
The additive classmap (`agent/duo-classmap.php`,
`cli/duo-classmap.php`) maps FQCN to path, which is what makes directory != namespace legal.
Sub-namespaces migrate later, per module, Kernel first.

## Index

| module | dir | layer | files | entry points | may depend on | purpose |
| --- | --- | --- | --- | --- | --- | --- |
| [agent:Kernel](agent-Kernel.md) | `agent/src/Kernel/` | kernel | 28 | `Canon`, `Db`, `OptionState`, `CommandRefusal`, +23 | `Kernel` | Dependency-free primitives — canonical JSON, database access, reference codecs, durable filesystem, identifiers, secrets and PII redaction — that everything else is built on. |
| [agent:Policy](agent-Policy.md) | `agent/src/Policy/` | policy | 18 | `Policy`, `ScopeContract`, `ManifestDispositions`, `ScopeClosure`, +3 | `Kernel`, `Policy` | Loads, validates and pins the manifest and site policy that decides which WordPress state Duo owns, and answers every ownership question the engine asks. |
| [agent:Grammar](agent-Grammar.md) | `agent/src/Grammar/` | policy | 24 | `Tokens`, `Blocks`, `OptionGrammar`, `SubKeyGrammar`, +20 | `Grammar`, `Kernel` | Declarative grammars and resolvers that turn manifest declarations and WordPress content syntax (blocks, shortcodes, tokens, options, taxonomies, user meta) into typed policy structures. |
| [agent:Repository](agent-Repository.md) | `agent/src/Repository/` | repository | 24 | `Ledger`, `CompiledArtifact`, `Snapshot`, `RepositoryCompiler`, +8 | `Grammar`, `Kernel`, `Policy`, `Repository` | The durable repository of owned state: compiled artifacts, identity registry, ledger, reference graph, snapshots and their validators. |
| [agent:Code](agent-Code.md) | `agent/src/Code/` | repository | 7 | `Code`, `CodeStateContract`, `CodeCompatibility`, `CodeDescriptorCompiler` | `Code`, `Kernel` | Ownership, staging, compatibility and materialization of code artifacts (themes, plugins, mu-plugins) as repository state. |
| [agent:Capture](agent-Capture.md) | `agent/src/Capture/` | engine | 18 | `Capture`, `TypedTableCapture` | `Capture`, `Code`, `Grammar`, `Kernel`, `Policy`, `Repository` | Reads owned state out of a live site into repository shape behind safety gates, a capture identity and a capture transaction. |
| [agent:Apply](agent-Apply.md) | `agent/src/Apply/` | engine | 26 | `ApplyPlanner`, `Apply`, `MenuMaterializer`, `RelationshipMaterializer`, +1 | `Apply`, `Code`, `Grammar`, `Kernel`, `Policy`, `Repository` | Plans and executes writes of repository state into a live site: apply planning, services, the authored transaction and the per-entity materializers. |
| [agent:Scope](agent-Scope.md) | `agent/src/Scope/` | engine | 7 | `ScopedApply`, `ScopedApplySession`, `ScopedStateOverlay`, `ScopedApplyWorkflow`, +3 | `Kernel`, `Policy`, `Repository`, `Scope` | Scoped (partial-site) execution — the state overlay, session and work projection that let apply and capture run over a subset of the repository. |
| [agent:Rebuild](agent-Rebuild.md) | `agent/src/Rebuild/` | engine | 9 | `RebuildSelection`, `NativeActions`, `RegenerationContextStore`, `RebuildRequest`, +5 | `Kernel`, `Policy`, `Rebuild`, `Repository` | Derived-state regeneration: negotiating, selecting and dispatching native or provider rebuild actions after a write. |
| [agent:Publication](agent-Publication.md) | `agent/src/Publication/` | engine | 3 | `Publish`, `PublicationJournal` | `Kernel`, `Publication` | Atomic publication of a state tree to disk plus the journal that makes an interrupted publication recoverable. |
| [agent:Promotion](agent-Promotion.md) | `agent/src/Promotion/` | engine | 11 | `PromotionLock`, `Deploy`, `ScopedPromotionAuthority` | `Code`, `Kernel`, `Policy`, `Promotion`, `Repository` | Deploy, lifecycle and promotion mechanics — planners, leases, locks, session journals and state handoff between environments. |
| [agent:Delete](agent-Delete.md) | `agent/src/Delete/` | engine | 7 | `Deletion`, `DeleteExecutor`, `DeleteGuardLockCoordinator`, `DeleteGuardReferenceScanner`, +2 | `Delete`, `Kernel`, `Policy`, `Repository` | Deletion authority, deletion guards and the executor that removes owned entities and records tombstones. |
| [agent:Init](agent-Init.md) | `agent/src/Init/` | engine | 13 | `Init`, `InitProtocol` | `Code`, `Init`, `Kernel`, `Policy`, `Repository` | First-contact onboarding of a site: probing, planning, confirming, journalling and recovering the initial owned baseline. |
| [agent:Review](agent-Review.md) | `agent/src/Review/` | engine | 17 | `Canary`, `ConvergenceVerifier`, `Journal`, `Lint`, +7 | `Code`, `Grammar`, `Kernel`, `Policy`, `Repository`, `Review` | Read-only projections over plans and state — lint and its reference scanners, coverage, pending, journal, orphans, canary, convergence verification and refresh export. |
| [agent:Adapter](agent-Adapter.md) | `agent/src/Adapter/` | adapter | 10 | `AdapterSources`, `Providers`, `ProviderActionBatchBuilder`, `AdapterRegistry`, +3 | `Adapter`, `Kernel`, `Policy`, `Promotion`, `Rebuild`, `Repository`, `Review` | The plugin-facing boundary: manifest sources, adapter registry, observation, site adapter certification and the provider SDK the engine calls through. |
| [agent:Command](agent-Command.md) | `agent/src/Command/` | surface | 1 | `Cli` | `Adapter`, `Apply`, `Capture`, `Code`, `Command`, `Init`, `Kernel`, `Policy`, `Promotion`, `Publication`, `Repository`, `Review`, `Scope` | The `wp duo …` WP-CLI surface: verb dispatch, argument parsing, refusal envelopes and operator output. |
| [agent:Assess](agent-Assess.md) | `agent/src/Assess/` | surface | 1 | `AssessInventory` | `Adapter`, `Assess`, `Code`, `Grammar`, `Kernel`, `Policy`, `Repository`, `Review` | Read-only inventory and projection commands answering 'what is here, and what can Duo do with it' before any write. |
| [cli:Transport](cli-Transport.md) | `cli/src/Transport/` | kernel | 6 | `EnvironmentDriver`, `Transport`, `CodeDeploy`, `SshTransport`, +1 | `Transport` | Carries bytes and commands to a target environment: the transport implementations, the environment driver handle and code deployment. |
| [cli:Plan](cli-Plan.md) | `cli/src/Plan/` | kernel | 3 | `PlanContract`, `PlanSummary`, `PlanView` | `Plan` | The plan wire contract and its operator/JSON renderings, with no I/O of its own. |
| [cli:Refresh](cli-Refresh.md) | `cli/src/Refresh/` | repository | 4 | `Refresh`, `RefreshFieldDiff` | `Refresh`, `Transport`, `agent:Code`, `agent:Kernel`, `agent:Policy`, `agent:Repository`, `agent:Scope` | The B/P/W refresh planner, field diff and local state materializer that reconciles git, production and working state. |
| [cli:Environment](cli-Environment.md) | `cli/src/Environment/` | engine | 2 | `Registry`, `EnvironmentLifecycle` | `Environment`, `Plan`, `Refresh`, `Transport` | The environment registry and lifecycle that materialize, bind and reap the environments the orchestrator drives. |
| [cli:Recovery](cli-Recovery.md) | `cli/src/Recovery/` | engine | 3 | `RollbackAuthority` | `Recovery`, `Transport`, `agent:Kernel` | Rollback authority and the verified/scoped rollback profiles that prove a target can be returned to a known state; round 3 adds profile selection, the checkpoint catalog and the recovery claim. |
| [cli:Onboarding](cli-Onboarding.md) | `cli/src/Onboarding/` | engine | 7 | `Doctor`, `Adopt`, `BootstrapEligibility`, `ClassificationBatch`, +3 | `Onboarding`, `Transport` | Adopting, initializing, diagnosing and triaging a site — the first-run and health mechanics behind adopt/init/doctor/classify/pending. |
| [cli:Adapter](cli-Adapter.md) | `cli/src/Adapter/` | adapter | 4 | `AdapterCatalog`, `AdapterDraft`, `AdapterObservation`, `ManifestValidate` | `Adapter`, `Transport`, `agent:Adapter`, `agent:Delete`, `agent:Kernel`, `agent:Policy`, `agent:Rebuild` | Authoring-side adapter tooling: catalog, draft, observation and manifest validation against the agent's policy. |
| [cli:Command](cli-Command.md) | `cli/src/Command/` | surface | 21 | `PassthroughCommand`, `CommandOutput`, `EnvironmentCommandPreflight`, `EnvironmentCommandOptions` | `Adapter`, `Assess`, `Command`, `Contract`, `Environment`, `Onboarding`, `Plan`, `Recovery`, `Refresh`, `Rehearse`, `Release`, `Transport`, `agent:Kernel`, `agent:Policy` | The `duo` verb handlers, agent passthrough, option parsing, preflight and operator output — and the only place composition across engine modules happens. |
| [cli:Assess](cli-Assess.md) | `cli/src/Assess/` | engine | 5 | `StackInventory`, `SurfaceCatalog`, `GapActions`, `AssessReport`, `AssessRenderer` | `Assess`, `Contract`, `Plan`, `Transport`, `agent:Adapter`, `agent:Assess`, `agent:Kernel` | The assess mechanism — stack inventory, surface catalog, gap actions, the `duo-assess-report/v1` document and its bounded renderer. `AssessCommand` lives in `cli/src/Command/`. |
| [cli:Release](cli-Release.md) *(reserved)* | `cli/src/Release/` | engine | 0 | _none yet_ | `Contract`, `Plan`, `Release`, `Transport`, `agent:Kernel`, `agent:Policy` | RESERVED (round 3): the frozen authorization plan, its renderer, the closed next-action set, the journey oracle. `ReleaseCommand`/`VerifyCommand`/`RecoverCommand` live in `cli/src/Command/`. |
| [cli:Rehearse](cli-Rehearse.md) *(reserved)* | `cli/src/Rehearse/` | engine | 0 | _none yet_ | `Contract`, `Plan`, `Rehearse`, `Transport`, `agent:Kernel` | RESERVED (round 3): rehearsal mechanism — the preview of what a release would touch, and the containment disclosure. `RehearseCommand` lives in `cli/src/Command/`. |
| [cli:Contract](cli-Contract.md) | `cli/src/Contract/` | policy | 5 | `ApplicationContract`, `ContractStore`, `ContractProposal`, `ContractProjection`, `ProjectionVocabulary` | `Contract`, `Plan`, `agent:Adapter`, `agent:Kernel`, `agent:Policy` | The per-site application contract as one object — manifests, site policy, evidence pins, bindings, capability report — plus `ProjectionVocabulary`, the one implementation of the spec-word projection. |

Not a module: `cli/duo` stays at `cli/duo` (the extensionless executable), and `recovery/` is untouched.

## Rules

1. **Every file belongs to exactly one module.** All 224 `agent/src` files and all 48 `cli/src` files are
   assigned in `tools/modules.json`; the assignment is validated against `git ls-files agent/src cli/src`.
2. **A module has exactly one layer.** That is the invariant that lets a directory-level dependency lint
   replace the file-level map in `tools/layers.json`. A file whose layer disagrees with its module's layer
   is a placement bug, not an exception.
3. **Ladder:** `kernel < policy < repository < engine < adapter < surface`. Within a root a module may
   reference only modules in a strictly lower layer, plus itself. Every same-layer or upward edge that
   exists today is enumerated in that module's `exceptions` and is a **ratchet**: it may shrink, never grow.
   The 15 upward module edges carry exactly 38 file-level edges, and that set is equal (verified
   programmatically) to the 38 entries already ratified in `tools/layers-exceptions.json` — the module map
   adds no new layer debt. The remaining 143 file-level edges recorded under `exceptions` are *intra-layer*:
   legal under `layers.json`'s "own or lower layer" rule, and listed because they are what makes the agent
   root one SCC.
4. **cli may reference agent; agent may never reference cli.** Cross-root edges are written `agent:<Module>`
   in `depends_on` and are exempt from the ladder, because `agent/src` is a library to the orchestrator.
5. **One writer per module.** A PR touches one module's files plus its callers' import/require lines only.
   Two agents may work in parallel iff their modules are disjoint and neither is a caller of the other's
   changed entry points.
6. **New code goes into a module directory.** There is no `agent/src/*.php` any more. A new file picks the
   module whose purpose sentence already covers it; if none does, that is a map change and needs its own PR
   against `tools/modules.json` plus a charter.
7. **A module's public surface is the classes named in its charter.** `entry_points` was derived from the
   actual reference graph, not from intent. A new cross-module reference to a non-entry-point class is a
   design change and must add that class to the charter in the same PR.
8. **The drop-in is still dependency-free.** Directories change nothing about that: each file keeps requiring
   its own dependencies by path, and the classmap stays additive.
9. **Composition happens at the surface; reserved modules carry no pre-authorised exceptions.** A verb
   boundary (`*Command`) lives in `cli/src/Command/` (or `agent/src/Command/`) and calls each engine module
   downward, handing one module's result to the next — that is why all eight populated cli modules are
   acyclic with zero exceptions, and the round-3 modules (`cli/src/{Assess,Contract,Rehearse,Release}/`)
   hold mechanism only. A reserved module's `exceptions` list is therefore empty: a zero-count "designed"
   exception is pre-authorised debt, which rule 3's ratchet forbids. The single unavoidable designed edge is
   surface→surface dispatch (`agent:Command` → `agent:Assess`), and it is recorded on the dispatching module.

## The module graph

Computed from the real reference graph (the `declarations()`/`references()` token scanner from
`sandbox/tests/offline/guards/regress_agent_src_requires.php`, run over all 272 files): 1,065 file→file edges,
of which 764 cross a module boundary.

- **`cli` is a clean DAG.** All eight populated cli modules are acyclic and have zero exceptions.
- **`agent` is one 15-module strongly connected component** — Adapter, Apply, Capture, Code, Delete,
  Grammar, Init, Kernel, Policy, Promotion, Publication, Rebuild, Repository, Review, Scope. Only
  `Command` (and the reserved `Assess`) sit outside it. This is the module-level shadow of the known
  160-file reference SCC.
- Removing the 38 ratified upward file edges leaves three smaller cycles, which is the honest work list:
  - `Policy <-> Grammar` (30 edges down, 7 back)
  - `Code <-> Repository` (6 down, 4 back)
  - `Apply <-> Capture <-> Delete <-> Init <-> Promotion <-> Rebuild <-> Review <-> Scope` (the engine SCC)
- `Publication` is the only agent module with no exceptions at all.

## Move mechanics

The move is behaviour-preserving: bytes inside the moved files change only in their `require_once` paths.
It is executed by one codemod, `tools/codemod/move-modules.php` (`--plan` prints every rewrite with its
diff; `--apply` performs the `git mv`s and the rewrites; both are idempotent and cwd-independent).
Counts below are that tool's own plan at `a6b0b9c` — quote `--plan`, not this table, in a PR.

1. `php tools/codemod/move-modules.php --plan --tree=agent` and `--tree=cli`, reviewed. Agent: 224 files
   moved, 404 files rewritten (1,499 literal paths, 92 loader requires, 4 dynamic requires, 2 layers-json
   passes, 4 scanner-recursion fixes, 16 in-test source assertions, 22 review items). Cli: 48 files moved,
   127 rewritten (260 literal paths, 47 loader requires, 5 `dirname(__DIR__)` bases, 11 review items).
2. `--apply` per tree — two commits, `agent` then `cli`, not one per module: the cross-module require
   rewrites are computed from a complete tree index, so a partial move is not a supported state (the tool
   tolerates a re-run only because an already-moved file is recorded as `already`). The rewriter is token-aware:
   a same-module target stays `__DIR__ . '/X.php'`, a cross-module target becomes `__DIR__ . '/../Kernel/X.php'`,
   guarded `class_exists()` forms survive by construction, and `dirname(__DIR__, N)` bases gain the depth
   delta. The four dynamic per-class requires that no path rewrite can reach — `RefreshPlan::loadCompiler()`
   (39 names), `AdapterCatalog::boot()`, `AdapterDraft::boot()`, `ManifestValidate::boot()` — are re-pointed
   through `agent/duo-classmap.php`, the only structure that still knows where a class lives.
3. Regenerate the derived artefacts: `php tools/classmap-generate.php` (writes by default; `--check` byte-compares. The map's *values* change,
   `src/X.php` → `src/<Module>/X.php`; `tests/Tooling/ClassmapTest.php` then re-derives them byte-identically),
   `php tools/api-surface.php --check` (the fixture records no paths, so it must be **unchanged** — that is the
   behaviour-preservation gate), and `phpstan-baseline.neon` (100 `path:` lines; the codemod rewrites the
   literals, regenerate if the ratchet test disagrees).
4. Four things the codemod cannot do, which are hand steps in the same PR:
   - `sandbox/tests/offline/policy/regress_manifest_validate.php:966` — `glob($repo.'/agent/src/*.php')` must become a
     recursive walk or `$engine` silently becomes the empty string and its symbol checks pass vacuously.
   - `sandbox/tests/offline/ecommerce/regress_woocommerce_contract.php:140` — `scandir($root.'/agent/src')` must become
     recursive, and its `!is_file(agent/src/WooCommerceContract.php)` assertion must become a
     "no `Woo*`-named file anywhere under `agent/src`" scan, or the DUO-3341 guarantee weakens to a
     directory listing of module names.
   - `tests/Tooling/DoctorTest.php:253-272` builds a synthetic `agent/src` tree; it stays valid, but confirm
     `tools/doctor.sh`'s closure walk is recursive against it.
   - `tools/layers.json` keys become `src/<Module>/X.php` (the codemod rewrites both it and the sorted
     `tools/layers-exceptions.json`); decide separately whether to retire the file-level map in favour of the
     directory lint (rule 2), which is a follow-up, not part of the move.
5. **Leave `manifests/**` alone.** The move itself is free — nothing hashes an `agent/src` path. What is not
   free is chasing the moved paths into `manifests/`: `ArtifactPolicyIdentity::manifest_rows()` folds each
   named interpreter/provider/regenerator file's `hash_file('sha256', …)` into that adapter's row, so
   rewriting one hook file moves its `adapter_digest`, and a deployed site with a compiled artifact then
   refuses with `compiled_artifact_manifest_mismatch`, "compiled manifest/interpreter set does not match
   active pins" (`agent/src/Repository/CompiledArtifactReader.php:39-42`), until the artifact is recompiled
   and the reviewed pin updated (`wp duo manifest-pin` emits the copy-pasteable object). The codemod
   deliberately leaves `manifests/**` untouched and reports it (23 files, 7,352 mentions).
6. Prose costs nothing: the codemod freely rewrites the `agent/src/X.php` mentions in `Makefile`,
   `.gitignore`, `sandbox/conformance/run.sh`, `tools/doctor.sh`, `docs/**` and `spec/**`. The only mentions
   that must stay stale are the ones inside `manifests/*.json` note strings and the hook files beside them —
   those bytes are folded into the identity row per step 5, and rewriting them changes adapter digests for no
   functional gain. (Bare `agent/src` directory mentions — `phpstan.neon.dist`'s `paths:`, `.gitignore`
   prose — must *not* grow a module segment and are left alone by design.)
