# Internal `wp wprism` commands

Every command on this page is real, shipped, and reachable on a target that
has the WPrism agent installed. None of them is part of the workflow. They are
listed here because the product spec requires that *normal operation never
depends on private identifiers, undocumented commands, or raw database
surgery* — and the honest way to satisfy that is to name what exists, say
what drives it, and say plainly that you should not be the one typing it.

That is what "documented internal" means here, and it is the whole contract
of this page:

> `wprism` never needs this; running it directly is outside the supported
> workflow.

Read that sentence as a boundary, not a warning label. These commands take
orchestrator-owned arguments — an exact promotion owner, an artifact hash, a
compiled-tree path, a policy snapshot — that only the host verb driving them
can compute. Supplying one by hand does not reproduce what the host does; it
reproduces the *shape* of what the host does, against a target that is
counting on the host's own fencing. The failure mode is not "the command
errors"; it is a lease nobody owns, or a database describing one code
revision underneath another.

If you are here because a release failed, you are in the wrong document:
read [recovery.md](recovery.md). `wprism recover` drives the raw recovery
actions for you, in the order they have to happen, including the mandatory
final step that people skip when they type them by hand.

## The table

"Driven by" names the host verb that invokes the command, or `nothing` where
no host verb does — followed by what surfaces the same facts through the
supported path.

| Command | What it is | Driven by | Supported status |
|---|---|---|---|
| `wp wprism verify-canonical` | The byte-level convergence re-read: a fresh recapture proving every entity in the compiled tree landed. Its `--compiled` and `--policy-snapshot` inputs are temporary files that only a mutating apply holds. | `wprism release` — the recapture runs inside the apply the release performs, where it fails closed. `wprism verify` asks the same question host-side, from a read-only plan re-read. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism orphans` | Lists structural-ref orphans in one declared authored-snapshot table, or repairs one scalar row through the typed mutation path. | `nothing` — `wprism assess` surfaces the same findings in its unknown / unclassified section. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism journal-report` | Aggregates the provenance journal and scores classification proposals against manifests. | `nothing` — `wprism assess` carries the classification evidence, and `wprism classify` acts on it. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism effect-coverage` | Scores each adapter's declared `effects[]` — recovery's rollback authority, per `recovery/EffectBundle.php`'s "the compiled inventory is the entire authority" — against the writes the provenance journal actually observed. Reports writes falling outside every declared effect selector, and declarations nothing exercised (which is explicitly not an error). Report-only by construction: it refuses nothing except a missing or unreadable journal, because reading an absent journal as "no writes" would publish a perfect clean sheet for a site that recorded nothing. | `nothing` — an adapter-authoring and review internal; the published false-positive baseline over the 18 shipped adapters is pinned by `sandbox/tests/offline/recovery/regress_effect_declaration_coverage.php`. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism journal-reset` | **Truncates the provenance journal.** Operator maintenance only. The journal is the only record of options observed at runtime, so a reset discards the evidence behind every pending classification: `wprism pending` goes quiet about options it could previously name, and `wprism classify`'s proposals lose their basis until traffic rebuilds the journal. It is not undoable and it is not a repair. It warns with the row count before truncating, and refuses outright if it cannot read what it would destroy. `wprism init` never requires it: journal rows are observations, not ledger identity, so a site journalling from first boot initializes with them intact. | `nothing`. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism promotion-begin` | Opens an exact owner/artifact promotion session on the target — the lease every mutating phase is fenced by. | `wprism promote`, `wprism deploy`, `wprism release`, `wprism env materialize`. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism promotion-begin-scoped` | The same session for a scope-contracted promotion, bound additionally to the scope hash. | `wprism promote --scope-contract=<path>`, and `wprism release` when the plan is scoped. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism promotion-complete-scoped` | Commits a scoped promotion session against its receipt and scope hash. | `wprism promote --scope-contract=<path>`, `wprism release`. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism promotion-abort` | Idempotent, exact-compare release of one owner/artifact lease row. | `wprism promote`, `wprism release`, `wprism recover` — which runs it as steps 1 and 4 of the recovery sequence, the fourth even when the import fails. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism code-preflight` | Reads whether a compiled artifact can be staged on this target, before anything is written. | `wprism deploy`, `wprism release`. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism code-stage` | Materializes the compiled code artifact into its staging location under the promotion lease. | `wprism deploy`, `wprism release`. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism code-finalize` | Swaps staged code into place and closes the code half of the lifecycle. | `wprism deploy`, `wprism release`. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism identity-export` | Exports the database-bound identity ledger (maps + sync states) beside a database backup. | `nothing` — an environment-cloning internal; `wprism env materialize` and `wprism rehearse` clone through the provider's own snapshot set instead. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism identity-import` | Restores a verified identity sidecar into its matching database backup. | `nothing` — the other half of the same cloning internal. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism refresh-export` | The read-only production export a merge reads: records, maps and code receipt bound to one database snapshot. Refuses an interrupted apply or an active promotion lease. | `wprism refresh`, `wprism rebase`. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism policy-to-manifest` | Promotes a site's already-classified policy rules into manifest-shaped facts on stdout. An authoring internal. | `nothing` — `wprism adapter-draft` reuses the same facts core and adds the offline proposers a human ratifies. See [adapter-authoring.md](adapter-authoring.md). | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism manifest-pin` | Answers what the engine would load for one adapter name on this target. An authoring internal. | `nothing` — `wprism adapter inspect` and `wprism manifest-validate` answer it from the host, offline. See [adapter-authoring.md](adapter-authoring.md). | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism adapter-probe` | Live SCHEMA facts for the tables one adapter draft proposes: column types and nullability, the real PRIMARY KEY, delete-guard index coverage in `lock_index()`'s own terms, declared foreign keys, an EAV twin, and natural-key uniqueness as one `COUNT(*)` vs `COUNT(DISTINCT …)`. Read-only, value-free, `authority: false`; it answers `wprism adapter-draft`'s named questions and decides nothing. An authoring internal, like the two above. | `nothing` — `wprism adapter-draft --evidence=<probe.json>` consumes its output. See [adapter-authoring.md](adapter-authoring.md). | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism adapter-deletion-feasibility` | Answers, for each guard of a PROPOSED deletion selector, whether `DeleteGuardEvaluator::lock_index()` finds a covering index on this target — the covering index name, or null with the reason ('no index leads with this column', 'prefix index of N bytes cannot cover a declared key of M'). The same computation the engine runs at deletion time, run at authoring time against a proposal nothing has declared. Read-only, value-free, `authority: false`: a covering index is necessary for a deletion contract and never sufficient, so it proposes no manifest fragment, names no cascade set, and refuses a proposal carrying one. Whether to advertise a selector stays a human's decision. An authoring internal, like the three above. | `nothing` — the proposal and the answer are both authoring artifacts a human reads. See [adapter-authoring.md](adapter-authoring.md). | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism adapter-survey` | Scans the third adapter source — one `wprism-adapter.json` at the root of each active plugin that bundles one — which lives in `WP_PLUGIN_DIR` and is therefore only reachable on the target. | `wprism assess`, through the target's own `wp wprism assess-inventory`, whose report carries the survey block. `wprism adapter list` names it as the source it cannot reach. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism assess-inventory` | The one read-only pass `wprism assess` is composed from: stack inventory, coverage, pending queue and the adapter survey in a single document. | `wprism assess`, `wprism contract`. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism code-inventory` | Reports one repository's lockable code components — `{root, component, version, tree_sha256, bytes, files}` from `code/wp-content` — with the exact digests the code lock and the compile gate use. Read-only; it never touches the live wp-content beside it. | `wprism code-classify`, which cross-checks it against the local checkout before it stops Git tracking any tree. | `wprism` never needs this; running it directly is outside the supported workflow. |
| `wp wprism version` | Prints the agent and spec version as JSON — the stable probe for tooling that is not `wprism`. | `nothing` — `wprism adopt` and `wprism doctor` read `WPRISM_AGENT_VERSION` through `wp eval`, so no WPrism verb depends on this command. | `wprism` never needs this; running it directly is outside the supported workflow. |

## What is deliberately not on this page

**Raw recovery.** `recovery/rollback-control.php` is the recovery runtime, not
an operator entry point. Its actions — `authority-status`, `active-evidence`,
`audit`, `status`, `checkpoint-request`, `code-release-request`,
`upload-bundle-request`, `effect-bundle-request`, `execute`,
`exclusion-request` — are driven by `wprism recover`, which prints the recovery
claim before acting, enforces code-first ordering, and refuses to start until
external writer exclusion is asserted. None of those three protections exists
when the runtime is invoked by hand. The guides therefore no longer publish a
four-command recipe; [recovery.md](recovery.md) publishes one verb.

**Internal identifiers.** A human view prints an internal identifier only when
a documented command consumes it: `wprism explain` prints its `<bucket>:<uuid>`
selector, `wprism recover --list` prints receipt ids that `--restore=<id>` takes,
and `wprism release` prints the plan digest that `wprism verify --plan=<digest>`
takes. Artifact hashes, lease owners, operation ids and session ids are in
`--format=json` and nowhere else. That is mechanically checked by
`sandbox/tests/offline/assess-contract/regress_mup_leak_audit.sh`, which also proves that every
command in the table above is either driven by a host verb or listed here.

## Where the reference lives

- [recovery.md](recovery.md) — the supported recovery path.
- [release.md](release.md) — what `wprism release` freezes, drives and verifies.
- [adapter-authoring.md](adapter-authoring.md) — the supported authoring path.
- [cli/README.md](../../cli/README.md) — every host verb and flag.
- [docs/recovery-runtime.md](../recovery-runtime.md) — the runtime contract
  itself, for the people who maintain it.
