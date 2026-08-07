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
classes before they shipped.

Every one of those proofs ran on sandbox-born sites. That is the boundary of
what we can honestly claim today.

## Horizons

**H1 — Correctness closure (running now).** Finish the boards' open surface
gaps: DUO-3226's per-surface split (menus meta-bypass at Urgent — the one known
silent-loss hole), DUO-3212 raw-ID elimination, the 3237/3249/3251 rulings'
deliverables, version-boundary matrix on pinned artifacts (DUO-3243 → DUO-3223).
Exit: no known path by which authored data silently fails to propagate.

**H2 — Adoption (DUO-3257, next).** Prove the product on its actual target: an
existing site on a real host over the SSH bridge, first-capture funnel usable at
real-site scale, unmanifested plugins named-and-counted, an operator-facing
adoption guide written from a real transcript. Usefulness begins here; H1 depth
is wasted if only sandbox-born sites can enter.

**H3 — Ecosystem.** Draft-manifest generation as *review* not authorship
(the treadmill lesson from VersionPress/Mergebot), a public manifest registry
with conformance evidence attached, host-agnostic bridges beyond SSH, and the
merge story surfaced as the headline capability — it is the market gap.

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
