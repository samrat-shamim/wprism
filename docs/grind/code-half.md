# Code-half clean-room grind

> **Archival.** The driver named below (`sandbox/tests/grind_code_half.sh`), its
> `make grind-code-half` target, and the `duo-code-half-probe` manifest it pinned
> were removed by the teardown train — #477 (remove the certification-evidence
> apparatus), #478 (remove the four demo manifests) and this prose pass. Nothing
> here is runnable today; the report stands as the record of what the grind
> proved and which product-path gaps it closed, not as instructions. The
> first-sync proof it hands off to is still live
> (`make grind-code-half-first-sync`).

Driver: `sandbox/tests/grind_code_half.sh` (removed — see the archival note above).

Run it from the repository root:

```sh
make grind-code-half
```

The driver creates one unique headless `sandbox/bin/pair.sh` pair and removes
that exact pair and its two `sandbox/siterepo/` directories in its exit trap.
It intentionally does **not** pass `--codebind`.  The repository contains
`code/wp-content` while the target starts without the probe under executable
`wp-content/plugins`; target files can only arrive through the materializer.

The probe has a tiny but order-sensitive upgrade:

- v1 activation creates `duo_code_half_probe_settings = "blue"` and schema
  `1`.
- v2 sees schema `1`, requires that setting to still be a scalar, adds its
  `color` table column, and converts the value to
  `{"schema":2,"color":"blue"}`.  It throws if the object was applied
  before migration.
- The canonical v2 options tree declares that same object.  A successful
  promotion therefore proves the public host ordering is `compile →
  promotion-begin/checkpoint → code-stage → lifecycle-retire → fresh-process
  lifecycle-activate/migration → code-finalize → apply`, not a raw state apply
  before code migration.

The script also asserts byte-level code-drift detection and healing; a late
desired file-versus-directory conflict and a changed obsolete tracked file
both leave the complete target plugins/themes tree hash and completed
`code_revision` unchanged; a retry then materializes or prunes the exact
owned file. Finally, the public promotion deactivates the still-executable
plugin before its component is pruned, preserves an unmanaged sibling, and
ends with recapture byte-convergence plus a clean `duo status`.

## Boundary proved by the grind

| Concern | Code half | State half | Promotion envelope |
|---|---|---|---|
| Source | `code/wp-content` exact bytes | canonical `state/` + `media/` | one frozen compiled artifact |
| Receipt | descriptor `code_revision` | `revision_hash` / ledger `applied_revision` | outer `artifact_hash` |
| Writer | `Code::stage/finalize` | `Apply` | host phase ordering + target lease |
| Never does | parse/apply canonical entities | copy/delete executable files | reinterpret either inner revision |
| Narrow bridge | plugin/theme inventory satisfies lifecycle identities | `active_plugins`, `template`, `stylesheet` express intent | real WordPress lifecycle APIs run between stage and finalize |

The code-only add/remove steps assert this separation rather than merely
describing it: `code_revision` and the outer `artifact_hash` change, while the
state `revision_hash` remains byte-identical. Every successful promotion also
checks that the completed code ledger matches `.code.code_revision`, the state
ledger matches `.revision_hash`, the live lease is gone, the durable session
matches the outer artifact, and the printed database checkpoint exists.

## Gaps harvested and fixed

The first live runs found three product-path gaps:

1. A late deterministic target type conflict could occur after earlier files
   had already been replaced, and a late changed obsolete file could occur
   after earlier removals. Stage now preflights the complete desired and prune
   inventories before the first known-conflict mutation; per-file temporary
   rename and mutation-time rechecks still catch ordinary changes and I/O.
   A descriptor becomes deletion authority only after its complete payload
   materializes, so a no-write preflight failure cannot claim a new component
   root on a later run.
2. Docker Compose can emit container lifecycle noise on stderr while the
   agent's structured compile diagnostic is on stdout. The host now preserves
   both distinct streams, so the compile-time code/state refusal remains
   actionable.
3. Lifecycle deactivation and an authored option tombstone in the same
   `options/core` revision looked like an ordinary three-way conflict after
   deploy made expected partial progress. Deploy now records the canonical
   pre/post hook hashes in the exact owner/artifact session, but only after a
   record-level check proves every non-lifecycle hook change already equals
   this artifact's non-`absent` desired value. Any other authored hook change
   stops before apply. Apply uses the pre-hook comparison only if its fresh
   environment hash still equals the recorded post-hook hash; a later edit
   fails closed through the normal conflict path.
4. Vendored plugin/theme headers could be outside a pinned adapter range even
   though the descriptor and state were internally valid. `CodeCompatibility`
   now checks bounded `Version` headers against resolved adapter rows during
   offline compilation and repeats the same source check under the stage
   lease, before the first target rename. The descriptor schema and code
   revision remain byte-only inventories; target-installed compatibility stays
   Deploy's independent `code_mismatch` proof.
5. `Requires Plugins` is a dependency-closure contract, not an
   `active_plugins` load-order contract. The source compatibility bridge now
   rejects duplicate slugs, cycles, and missing/inactive providers before code
   materialization. Deploy activates providers before dependents and then
   restores the exact authored/native `active_plugins` order; canonical state
   is never silently reordered.

A lifecycle API can commit an option and then throw before deploy reaches its
post-hook snapshot. Deploy therefore also publishes a pre-hook attempt receipt
inside the exact promotion session before every mutating lifecycle window. A
failed window leaves that receipt durable: apply and any different promotion
owner/artifact refuse until the retained pre-lifecycle checkpoint and known
pre-promotion code revision are restored. The first-sync proof—where no state
base exists at all—lives in
[`code-half-first-sync.md`](code-half-first-sync.md).

The inverse proof is positive rather than inferred: the exact promotion session
records successful retire and activate phases in order, including no-ops, and
code-finalize refuses to mint the completed code revision without both.

The database promotion lease serializes Duo writers, not arbitrary processes
with direct filesystem access. This v0 materializer therefore requires
operational exclusion of non-Duo writers from `WP_CONTENT_DIR` during
stage/finalize. Stable-tree symlinks and path/type replacements are refused,
but the PHP path APIs are not claimed as an `openat(O_NOFOLLOW)` defense
against an adversarial concurrent directory-to-symlink swap.

The accepted clean-room run finishes with a real v2 deactivation hook count of
one, removal of the authored option through a `--with-deletes` tombstone,
pruning of only the previously owned plugin component, preservation of the
unmanaged sibling, byte-identical recapture, and a zero-finding host status.

The broader plugin/MU/theme/dependency/recovery matrix lives in
[`code-half-ecosystem.md`](code-half-ecosystem.md).
