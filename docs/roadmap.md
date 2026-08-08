# Duo — owner roadmap

Owner lane: delegated to the team-lead session (2026-08-07). Rulings are posted on
Linear issues as `OWNER RULING` comments; this document holds the direction those
rulings serve. It changes only by owner commit.

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

The honest boundary has moved but still exists. Proofs now include a real
SSH-host adoption run (DUO-3257 phase 1) and a signed production-form SSH
crash-rollback certification (DUO-3299) — no longer sandbox-born-only. What
remains unclaimed: no third-party production site has adopted; `duo promote`
does not yet select the verified-rollback profile (promotion is
operator-directed; a successful database import is not a verified rollback);
and seven of eight certified adapters cite a single version-matrix test while
the live conformance evidence stays outside the bundle (DUO-3306).

## Horizons

**H1 — Correctness closure (exit reached 2026-08-08).** The board drained:
surface-boundary program complete, raw-ID elimination shipped, every ruling's
deliverables landed, version-boundary matrix certified on pinned artifacts,
WooCommerce closed as a contract (DUO-3225) — partly by honest reduction
(product deletion support removed rather than under-proven). Exit criterion
met as stated: no known path by which authored data silently fails to
propagate. Tail items: DUO-3248 (journal VARCHAR risk, Backlog), DUO-3274
fixture-staleness sweep and DUO-3301 TEC occurrence lag (in flight).

**H2 — Adoption (running).** Phase 1 grounded the SSH path on a real host and
same-day-fixed what it surfaced (DUO-3287, DUO-3290). The rollback program
(DUO-3291 design → DUO-3293…3299) built and certified the machinery: receipt +
generation fence, encrypted checkpoints, atomic code releases with verified
restore, upload journaling, effect contracts, crash certification. Next: wire
`duo promote` to select the certified profile, then adoption phase 2 (real
third-party site, operator-facing guide from a real transcript).

**H3 — Ecosystem.** First artifact shipped early: the evidence-bound
capability registry (DUO-3227) — the product-claims half of the future public
registry. Still ahead: per-adapter conformance evidence in the bundle
(DUO-3306), draft-manifest generation as *review* not authorship (the
treadmill lesson from VersionPress/Mergebot), the registry made public with
evidence attached, host-agnostic bridges beyond SSH, and the merge story
surfaced as the headline capability — it is the market gap.

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
- A successful database import is not a verified rollback. Promotion stays
  operator-directed until `duo promote` selects the certified
  verified-rollback profile (DUO-3291 ruling; machinery certified in
  DUO-3299).
