# Duo — owner roadmap

Owner lane: delegated to the team-lead session (2026-08-07). Rulings are posted on
Linear issues as `OWNER RULING` comments; this document holds the direction those
rulings serve. It changes only by owner commit. Honest boundary refreshed
2026-08-13 (post-DUO-3310/3306/3450).

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
registry that is the only source of product claims (README, docs, CLI,
readiness all consume it; `make release-gate` fails on drift).

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
target qualifies. Next: adoption phase 2 — a real third-party site, an
operator-facing guide distilled from a real transcript, and field evidence
that real targets qualify for the verified profile.

**H3 — Ecosystem.** The evidence-bound capability registry shipped early
(DUO-3227), and certified claims now bind to named per-adapter conformance
evidence (DUO-3306; scoped bundles DUO-3450). Draft-manifest generation exists
as tooling — `duo adapter-draft` emits inert `_draft` candidates a human
ratifies, *review* not authorship (the treadmill lesson from
VersionPress/Mergebot) — but the community loop around it does not. Still
ahead: the registry made public with evidence attached, host-agnostic bridges
beyond SSH, and the merge story surfaced as the headline capability — it is
the market gap.

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
- Certified status derives only from the generated registry bound to
  executable evidence; product prose never hand-claims support. Dispositions
  are reviewed inputs kept separate so a manifest cannot certify itself
  (DUO-3227; release-gate enforces byte-level agreement).
- Capability *reduction* is a legitimate certification outcome: working but
  unprovable behavior is removed and refused, not shipped under-proven
  (DUO-3225: Woo product deletion → fail-closed boundary).
- A successful database import is not a verified rollback (DUO-3291 ruling —
  this part stands). The "promotion stays operator-directed" half was
  superseded 2026-08-09 by DUO-3310: `duo promote` now selects the certified
  verified-rollback profile automatically when the target proves every
  rollback capability, and remains operator-directed with an explicit warning
  when it does not.
