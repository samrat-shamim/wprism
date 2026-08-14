# Duo — owner roadmap

Owner lane: delegated to the team-lead session (2026-08-07). Rulings are posted on
Linear issues as `OWNER RULING` comments; this document holds the direction those
rulings serve. It changes only by owner commit. Honest boundary refreshed
2026-08-14 (product-spec ratification; post-DUO-3310/3306/3450).

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
classes before they shipped. Above it now sits a certification layer: reviewed
dispositions, content-addressed evidence bundles, and a generated capability
registry that is the only source of current platform-certified product claims
(README, docs, CLI, readiness all consume it; `make release-gate` fails on
drift). No site-certified product claim exists yet; that path is gated by H3.

The honest boundary has moved again. Proofs now include a real SSH-host
adoption run (DUO-3257 phase 1), a signed production-form SSH crash-rollback
certification (DUO-3299 — harness-signed Ed25519 evidence, not third-party
attestation), automatic verified-rollback promotion — `duo promote` selects the
certified profile whenever the target proves every rollback capability, and
falls back to operator-directed with an explicit warning otherwise (DUO-3310) —
and certified claims bound to named conformance evidence with per-manifest
scoped certification bundles (DUO-3306, DUO-3450). What remains unclaimed: no
third-party production site has adopted, no field evidence yet shows a real
target qualifying for the automatic verified profile, and the registry is not
yet public.

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
qualification the evidence-bound route through the ecosystem long tail. The
evidence-bound platform registry (DUO-3227), named per-adapter conformance
evidence (DUO-3306; scoped bundles DUO-3450), and inert human-ratified
`duo adapter-draft` candidates are shipped foundations. The sequence is:

1. **Field grounding and adoptability** — H2's calibration and validation
   cohorts; close internal-ID, undocumented-command, raw-recovery, and
   unbounded-output leaks; freeze outcome, cost, and attention thresholds.
2. **Site certification** — composed application contracts, a registered
   certification gate and attestation, generated per-site capability
   projections, semantic oracles, exact dependency invalidation, and the
   production launch gate defined by the spec.
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
  (DUO-3223 ruling; lockfile schema leaves room for vendored premium ZIPs).
- natural_key identity: the key supplies deterministic bootstrap identity;
  the ledger supplies continuity thereafter, so renames retain UUIDs and are
  surfaced as informational observations (DUO-3237 revised ruling).
- Manifest-layer reclassification of core options is legal and loud
  (DUO-3249 ruling: default_category derived under Polylang).
- Forced overrides must disclose their consequences and ship an exit path
  through duo, never through operator SQL (DUO-3251 ruling).
- Sandbox pairs and test fixtures are disposable by design; scripts touching a
  namespace must prove it dead first (linear-loop.md field notes).
- Current platform-certified status derives only from the generated registry
  bound to executable evidence; product prose never hand-claims support.
  Dispositions are reviewed inputs kept separate so a manifest cannot certify
  itself (DUO-3227; release-gate enforces byte-level agreement). Future
  site-certified status is separate and exists only in a generated per-site
  projection backed by reviewed declarations, current evidence, a registered
  certification attestation, and environment bindings.
- Capability *reduction* is a legitimate certification outcome: working but
  unprovable behavior is removed and refused, not shipped under-proven
  (DUO-3225: Woo product deletion → fail-closed boundary).
- A successful database import is not a verified rollback (DUO-3291 ruling —
  this part stands). The "promotion stays operator-directed" half was
  superseded 2026-08-09 by DUO-3310: `duo promote` now selects the certified
  verified-rollback profile automatically when the target proves every
  rollback capability, and remains operator-directed with an explicit warning
  when it does not.
