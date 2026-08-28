# Duo — owner roadmap

Owner lane: delegated to the team-lead session (2026-08-07). Rulings are posted on
Linear issues as `OWNER RULING` comments; this document holds the direction those
rulings serve. It changes only by owner commit. Honest boundary refreshed
2026-08-20 (evidence-seal retraction; post-teardown of DUO-3306/3450).

## Thesis

WordPress sites become branchable like code: branch, edit, merge, promote.
Plugins/themes unmodified; authored state canonical in git; runtime data
environment-local and untouched. The posture that keeps this shippable where
predecessors died: **loud, blocking, scoped** — a guarantee is stated exactly or
the behavior is refused, never approximated.

## Where we are (honest)

The engine's correctness core is real: three-axis classification with
field/key/row granularity, typed identity + structure-aware rewriting, atomic
capture, compile-gated publication, truthful promotion (deploy-before-apply,
promotion/capture locks), deletion tombstones with guards, secret scanning,
version-pinned adapter contracts, and an evidence culture (conformance sweeps,
grind scenarios, certification matrices) that has repeatedly caught silent-loss
classes before they shipped. Above it sits the claim model: a manifest declares
what it covers, a human reviews that into the same capsule's
`package/disposition.json` with the reason written down, and package-local or
participant-declared suites exercise it against a live pair. The capability
tool renders an aggregate review projection from the capsule and platform
sources; checked product pages describe the model without copying adapter rows.
What a status does NOT mean is that a digest binds it to an artifact set or a
particular run.

The honest boundary has moved again — and in one direction it moved back.
Proofs now include a real SSH-host adoption run (DUO-3257 phase 1), a signed
production-form SSH crash-rollback certification (DUO-3299 — harness-signed
Ed25519 evidence, not third-party attestation), automatic verified-rollback
promotion — `duo promote` selects the certified profile whenever the target
proves every rollback capability, and falls back to operator-directed with an
explicit warning otherwise (DUO-3310) — and an operator's own Ed25519 authority
over adapters it authored, which is the one thing that reads `Site-certified`
(T6). The retraction: the evidence seal is gone. Per-manifest scoped
certification bundles (DUO-3306, DUO-3450) sealed a claim to a content-addressed
record, that apparatus was torn out, and nothing replaced it. A claim now rests
on a human's reviewed disposition and on conformance suites that are re-run
rather than sealed — weaker in kind than a bound record, and honest about it.
What remains unclaimed: no third-party production site has adopted, no field
evidence yet shows a real target qualifying for the automatic verified profile,
the contract attestation signer ships with an EMPTY trust root (`duo contract
<env> attest` signs under a key an operator provisions in
`.duo/contract/authorities.json`; no site ships with one, so every application
contract in the field still writes `attestation.state: unsigned`), and there is
no public registry.

## Horizons

**H1 — Correctness closure (exit reached 2026-08-08).** The board drained:
surface-boundary program complete, raw-ID elimination shipped, every ruling's
deliverables landed, version-boundary matrix certified on pinned artifacts,
WooCommerce closed as a contract (DUO-3225) — partly by honest reduction
(product deletion support removed rather than under-proven). Exit criterion
met as stated: no known path by which authored data silently fails to
propagate. The former tail items have since landed: DUO-3248 (journal table
provenance bounded), DUO-3274 (fixture-staleness sweep reconciled), DUO-3301
(TEC render checks made truthful).

**H2 — Adoption (running).** Phase 1 grounded the SSH path on a real host and
same-day-fixed what it surfaced (DUO-3287, DUO-3290). The rollback program
(DUO-3291 design → DUO-3293…3299) built and certified the machinery — receipt +
generation fence, encrypted checkpoints, atomic code releases with verified
restore, upload journaling, effect contracts, crash certification — and
DUO-3310 wired `duo promote` to select that profile automatically when the
target qualifies. Next: product-spec Phase A — a calibration cohort of at
least three transactionally active single-site WooCommerce sites across two
agencies, followed by the held-out validation cohort defined in
[docs/product-spec.md](product-spec.md). The outputs are an operator-facing
guide distilled from real transcripts, frozen launch thresholds, and field
evidence about real targets and verified recovery.

**H3 — Agent-native product and ecosystem.** The ratified
[product specification](product-spec.md) keeps branchable WordPress as the
market wedge, makes agencies the initial customer, and makes agent-driven
qualification the route through the ecosystem long tail. The shipped
foundations are narrower than this section once claimed: reviewed manifest
dispositions no manifest can reach (DUO-3227), per-adapter conformance suites
that are run rather than sealed, an operator's own signing authority over
adapters it authored, and inert human-ratified `duo adapter-draft` candidates.
The evidence-bound part is what is missing — a claim is backed by review plus a
re-runnable live suite, not by a record that binds it — so building the
qualification route back up to "evidence-bound" is work in this horizon, not a
foundation under it. The sequence is:

1. **Field grounding and adoptability** — H2's calibration and validation
   cohorts; close internal-ID, undocumented-command, raw-recovery, and
   unbounded-output leaks; freeze outcome, cost, and attention thresholds.
2. **Site certification** — composed application contracts, the RULING on
   which trust root may attest a contract (the mechanism ships: an Ed25519
   signer under the site root, verified at every read, refusing to mint
   without an operator-provisioned key — what is deferred is whether a
   platform root may ever attest one, which refuses by name today as
   `contract_attestation_trust_root_unsupported`), generated per-site
   capability projections,
   semantic oracles, exact dependency invalidation, and the production launch
   gate defined by the spec.
3. **Safe scale** — more precise bounded requalification, fleet policy reuse
   without evidence or authority confusion, broader sandbox/effect contracts,
   and stronger conflict, plan, verification, refusal, and recovery UX.
4. **Ecosystem compounding** — only after demand and privacy proof: consented
   field-evidence aggregation, public registry evidence, host/agent-platform
   partnerships, and host-agnostic bridges beyond SSH.

The merge story remains the headline capability. Community evidence is review,
never authorship, and no site-certified Ready claim ships before the Phase B
launch gate passes.

## Standing decisions

- Artifact sourcing: wp.org release archive, sha256-locked, no latest-fallback
  (DUO-3223 ruling). Extended from the test estate to the PRODUCT code half on
  2026-08-21 (owner ruling, shipped as DUO-3499): Duo owns a lockfile,
  `code/duo-code.lock.json`, and the egress constraint is absolute — the
  agent/production target NEVER fetches from a registry, so classification and
  resolution are orchestrator-host work only. That constraint is what keeps
  `code_release_provider`'s shipped probe attestation ("off-target build and
  dependency resolution … no target Git history or registry credentials") true
  for a site using both. Resolution itself shipped as DUO-3500 (`duo
  code-resolve`, and the automatic `<verb> phase: code-resolve` inside deploy
  and promote) for `local` and `docker`; host-to-target push over ssh shipped
  as DUO-3514 — the host resolves into a throwaway staging worktree from the
  TARGET's own lock, ships one tar, and the trees are verified target-side
  against `tree_sha256` in a staging directory before anything is renamed into
  `code/wp-content`. DUO-3514 also shipped the retention half:
  `duo recover <env> --prune-retained=<keep-n> [--confirm-prune]` is the only
  verb that removes a retained release checkpoint, and nothing prunes
  automatically.
- Git never carries third-party code (owner ruling, 2026-08-23). There is no
  vendoring mode: every plugin and theme component is LOCKED — against its
  wp.org release, or against an archive the operator imported on the host with
  `duo code-import` (`imported-archive`, recorded by digest alone: no URL, no
  path) — or declared FIRST-PARTY with `--first-party=<root>/<slug>`, and
  anything else blocks `duo init`, refuses `duo code-classify`, and refuses
  every compile (`code_component_undeclared`). `--code=full` and the
  `vendored-archive` lock origin are retired; `duo-code-lock/v2` carries the
  `first_party` declarations beside `components`. Premium archives are the
  operator's to move between hosts, never Duo's to download.
- natural_key identity: the key supplies deterministic bootstrap identity;
  the ledger supplies continuity thereafter, so renames retain UUIDs and are
  surfaced as informational observations (DUO-3237 revised ruling).
- Manifest-layer reclassification of core options is legal and loud
  (DUO-3249 ruling: default_category derived under Polylang).
- Forced overrides must disclose their consequences and ship an exit path
  through duo, never through operator SQL (DUO-3251 ruling).
- Sandbox pairs and test fixtures are disposable by design; scripts touching a
  namespace must prove it dead first (linear-loop.md field notes).
- Current platform-certified status derives only from the generated capability
  document (`docs/capabilities.md` and the README block); product prose never
  hand-claims support. Dispositions are reviewed inputs kept separate so a
  manifest cannot certify itself (DUO-3227; release-gate enforces byte-level
  agreement between the generated prose and those inputs). Site-certified
  status is separate and exists only in a generated per-site projection backed
  by reviewed declarations, conformance suites re-run against that site's pair,
  a certificate signed under a named trust root and pinned to exact adapter
  bytes, and environment bindings. The adapter-level certificate ships (T6) and
  so does the contract attestation signer, under the SITE trust root only and
  with that root empty by default; the platform-root ruling is H3 work.
- Capability *reduction* is a legitimate certification outcome: working but
  unprovable behavior is removed and refused, not shipped under-proven
  (DUO-3225: Woo product deletion → fail-closed boundary).
- A successful database import is not a verified rollback (DUO-3291 ruling —
  this part stands). The "promotion stays operator-directed" half was
  superseded 2026-08-09 by DUO-3310: `duo promote` now selects the certified
  verified-rollback profile automatically when the target proves every
  rollback capability, and remains operator-directed with an explicit warning
  when it does not.
