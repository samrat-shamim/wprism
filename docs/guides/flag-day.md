# The v3 flag day — a cohorted rollout runbook

**Read this when** you are moving spec `2` repositories or adapters into the
current agent `0.7.0` / spec `3` lane, or when you are deciding whether a
future agent/spec rollout can still be rolled back.

This is the operator's half of [spec/repo-format.md § v3.12](../../spec/repo-format.md).
The spec says what the flip changed; this page says what you run, in what
order, and what makes you stop.

---

## The one-paragraph version

The bump moves two `define()` lines in the agent and the two members
`platform/adapter-library/capabilities/platform.json` restates. **No shipped adapter digest
moves, and no manifest file moves a byte** — that is measured, not asserted, by
`sandbox/tests/offline/policy/regress_spec_v3_digest_neutrality.php` against
identity fixtures frozen before the defines moved. Every site keeps its
`site_hash` and every shipped adapter digest it pins.

**Your fleet then splits in two, and which half a site is in decides how much
work it is.**

- **A site pinning only shipped adapters is fully neutral.** Its
  `manifest_hash`, its content pins and its compiled `revision_hash` all hold,
  and its compiled artifact keeps verifying. Steps 5 and 6 are no-ops for it.
- **A site holding a certified site adapter is not.** Its certificate withdraws
  (see [the M6 consequence](#what-the-bump-does-to-certificates-the-m6-consequence)),
  the certificate-derived digest folded into `manifest_rows()` moves with it,
  and that carries the site's own **`manifest_hash` and `revision_hash`** —
  so its held compiled artifact **refuses** with
  `compiled_artifact_manifest_mismatch` until it is recompiled. Steps 5 and 6
  are mandatory for it, in that order.

The split is measured per site by
`sandbox/tests/offline/guards/regress_spec_migration_rehearsal.php`, whose
per-site transition report prints `held` or `MOVED` for each of the nine
rehearsal sites, and it is **predicted per site before the bump** by step 1.
One more thing is not neutral for anybody: every site's **`artifact_hash`**
moves, because a resolved adapter row carries its capability claim and that
claim embeds the platform boundary.

Nothing in a site repository needs re-stamping. Both documents that carry the
wire version — a manifest's `spec_version` and `site.wprism.json`'s own — are
judged against the acceptance window `{2, 3}`, so a repository and a library
that declare `2` keep loading unchanged. **That is what makes the flag day
reversible, and it is what gate G3 protects.**

---

## Before you start

You need, per cohort:

- the release you are shipping, checked out on the machine you will run from —
  every command here reports about **the agent state this process runs at**,
  which is why there is no `--target` flag anywhere in it;
- the site repositories, checked out;
- for each site, whatever it is holding that git does not: its compiled
  artifact, its scope contract, its frozen policy snapshot. Each is optional
  and each one you omit becomes a `deferred` row naming the flag that answers
  it — never silence, and never folded into a pass;
- the Ed25519 secret key each certified site adapter was signed under. If you
  do not have it, that adapter stays `uncertified` after the bump. That is
  honest and non-blocking — `plan` and `apply` keep working — but readiness and
  host promotion stay closed for it until someone with the key re-signs.

---

## The loop, per cohort

Run these in order. A cohort is a set of sites you are willing to move
together and to stop on together.

### 1. Preflight — before the bump, on the pre-bump agent

```
wprism adapter doctor --migration --repo=<site-repo> \
    [--artifact=<artifact.json>] [--scope-contract=<scope-contract.json>] \
    [--snapshot=<snapshot.json>] --format=json
```

Run it from the release you are **about to** ship, against each site in the
cohort. It enumerates, per site: every certificate that will go stale and
whether its signing key id is reachable, every pin that would move (expected:
none), every held identity that will need re-projection, and every host
contract pinning `registry_sha256`.

**Record the predicted set.** Step 4 compares against it.

One `unclassified` row makes the run non-green and its exit code 1. That is
deliberate: an outcome nobody reviewed must never read as clean, and on a flag
day it is the only available signal that an unenumerated gate exists.

### 2. Rehearsal — green for this cohort's pin shapes

```
make regress-spec-migration-rehearsal
```

The rehearsal drives a synthetic nine-site estate A→B→A through child processes
at each agent state and reports what moved. Two things must hold before a
cohort proceeds:

- the rehearsal is green, including its **forward-then-back** case: a site
  driven to the new state and back returns to its **exact** pre-flag state —
  digests, pins, certificate verification, compiled artifact, frozen snapshot;
- **the preflight's predicted invalidation count equals the rehearsal's
  observed count** on the same pin shapes. A mismatch is a No-Go: the two are
  independent derivations of the same number, and they disagreeing means one of
  them is reading a gate the other does not know about.

### 3. Bump

Deploy the new `agent recovery` archive; the staged agent already contains its
assembled adapter library. It travels as **one**
archive through four atomic journal surfaces, so a site never observes half of
it: there is no window in which a v2 agent sees a v3 manifest library.

Do not assemble the halves by hand. A mixed bundle refuses at load with
*"platform version disagrees with the loaded agent"*, naming the document that
disagrees — that refusal is exercised in
`regress_spec_v3_digest_neutrality.php`, and the remedy is to redeploy the
archive, never to patch one half.

### 4. Post-verify — predicted == observed

Run the same preflight again, now on the bumped agent, and compare its observed
set against the set you recorded in step 1.

**If the observed set diverges from the predicted set, HALT the rollout.** Do
not proceed to the next cohort and do not continue within this one. A
divergence is not a discrepancy to reconcile later; it is the one signal
available that a gate exists which nobody enumerated, and every later cohort
would hit it blind.

### 5. Recertify

```
wprism adapter recertify <site-repo> --secret-key-file=<f> [--format=json]
```

One invocation per site re-signs **every** certified site adapter under the key
each certificate already names.

- **Idempotent by reuse, not by a flag.** An unchanged input re-signs
  byte-identically and keeps its original `created_at`, so the row reads
  `unchanged` and nothing is written. Re-running it across a cohort whose
  members are in different states is safe and is the intended usage.
- **All-or-nothing.** The site trust root and every certificate file are staged
  before the first signature; any failure restores all of them and the command
  reports that it did. A half-recertified repository — some adapters bound to
  the new boundary, some to the old — is the outcome this verb exists to make
  unreachable.
- **One key per invocation.** A certificate under a different key is reported as
  a blocked row naming that key id, and the run is not green. Two keys means
  two invocations.
- **It re-signs only claims it could have DERIVED.** A certificate carrying a
  disposition the site wrote itself (`wprism adapter certify --ratification-file`,
  spec/repo-format.md § v3.17) is a blocked row too: re-deriving here would
  replace that site's own argument, per-refusal prose and all, with the canned
  floor — under the site's own key, and with nothing in the report saying a
  claim had changed. Re-sign those with
  `wprism adapter certify <site-repo> --name=<n> --secret-key-file=<f>
  --ratification-file=<the document the site wrote>`.

> **This step closes the rollback window for that site.** See gate G3 below.

### 6. Re-project what moved, per site

```
wprism release --spec-v3 <site-repo> [--artifact=<f>] [--scope-contract=<f>] \
    [--snapshot=<f>] [--format=json]
```

It calls the same preflight, writes the site's **prior** pin objects into
`.wprism/migrations/` **before** emitting any new one, and prints the pin objects
the post-flip library resolves. It writes nothing to `site.wprism.json`: updating
a pin stays an explicit review act (`wprism adapter pin`).

Under digest neutrality the emitted objects **equal** the prior ones for every
shipped adapter. A site holding a certified site adapter is where they differ,
because the certificate withdrawal moves that adapter's row.

**Run this step AFTER step 5, never before it, and on a certificate-holding
site do not stop at the pin objects.** Recertifying moves that adapter's digest
a second time — from the withdrawn value back to a certified one — so a pin or
an artifact produced between the bump and the recertify is stale the moment
step 5 runs. On a site whose step-4 report showed `manifest_hash` moving you
must also:

1. **Recompile and re-pin.** The held artifact is refusing with
   `compiled_artifact_manifest_mismatch`; that is rule 2's refusal working
   correctly, and recompile-and-re-pin is the only remedy — there is no
   fallback and the architecture would refuse one. `wprism adapter pin` emits the
   copy-pasteable object.
2. **Re-project anything that pinned `artifact_hash`** — scope contracts,
   scoped mutation authorities, scoped rollback claims. This part applies to
   **every** site, neutral or not, because `artifact_hash` moves fleet-wide.

A recovery checkpoint needs nothing: it binds *manifest* inputs, not the
artifact document, and the rehearsal measures it still binding after the bump.

### 7. Next cohort

Only after steps 4, 5 and 6 are clean for every site in this one — which for a
certificate-holding site means its artifact verifies again, not merely that the
commands exited 0.

---

## What the bump does to certificates (the M6 consequence)

`spec_version` is inside every signed `statement.platform`. So on the flag day
`assertPlatformBinding()` raises `StalePlatformSiteAdapterCertificate` against
**every certificate in the field**, because every one of them was signed under
2:

> site adapter '<name>' certification was signed under spec version 2, which is
> not the spec version 3 this agent publishes

**Nothing bricks.** On the live scan and inside a frozen snapshot alike, the
adapter degrades to **uncertified support** rather than the source being
refused: it keeps loading, `plan` and `apply` stay available, and readiness and
host promotion stay blocked for that adapter until it is re-signed. Forgery,
authority anomaly and wrong-binding remain hard whole-source refusals — the
line the engine already draws.

**The withdrawal writes nothing.** Rolling back restores the claim untouched,
because nothing on disk changed when the certificate went stale.

**The remedy is step 5.** `wprism adapter recertify <repo>`, per site.

---

## Gate G3 — the rollback window, and the three acts that close it

Until G3 is opened **by a dated, deliberate decision**, rollback is the shipped
atomic bundle swap run backwards: redeploy the prior `agent recovery`
archive and the site is exactly where it was. Every pin matches, every compiled
artifact verifies, every scope contract and identity sidecar holds, and
`platform.json` reverts to the bytes every pre-flag certificate signed over — so
certificates that were valid before the bump verify again. That is measured, not
argued: the rehearsal drives a nine-site estate A→B→A and asserts the second
state-A observation is byte-identical to the first.

One caveat, and it is about what *you* did rather than about the bump: that
symmetry describes a site you have not written to. A certificate-holding site
that reached step 6 holds an artifact recompiled against the post-flip
`manifest_hash`, and rolling back moves that hash home again — so the recompiled
artifact refuses until it is recompiled once more, or the pre-flip one is
restored. This is why step 4 of the rollback list below is not optional.

**Three acts make that lossy, and they do not carry the same rule.** The
flag-day itself performed neither act 1 nor act 2. Paid Memberships Pro became
the first deliberate act-1 migration; eight provider-bearing manifests later
followed to use `manifest-provider-runtime/v1`. Each affected adapter therefore
requires its own recompile/re-pin. Act 2 remains absent. Act 3 is the act this
runbook schedules at step 5, and authorising that cohort remains an explicit
certificate-rollout decision rather than a side effect of the version bump.

| # | Act | Why it is one-way |
|---|---|---|
| 1 | The first shipped manifest stamped `spec_version: 3` — now Paid Memberships Pro | The v2 agent's window is `{1, 2}`. It refuses the current PMPro manifest wholesale; rollback must restore the prior manifest bytes and recompile/re-pin sites that adopted the new digest, never copy only the old agent. |
| 2 | The first **repository** re-stamped to `spec_version: 3` | Same window, other carrier: the restored agent refuses to compile that repository at all. No verb performs this act — `release --spec-v3` deliberately does not — precisely because a routine "tidy the version field" commit could otherwise perform it by accident. |
| 3 | The first certificate **re-signed** after the bump — i.e. running step 5 | Be precise about what this is **not**: the `/v2` statement wire already ships and is already in the field, so the wire generation strands nothing. What strands the rollback is the **platform binding** — a re-signed certificate binds `spec_version: 3`, and the restored v2 agent raises `StalePlatformSiteAdapterCertificate` against it exactly as the v3 agent did against the old one. The re-sign also **overwrites** `adapters/certifications/<name>.json`, so the certificate the rollback target could have verified is gone. |

Act 3's cost is bounded and symmetric rather than absolute, which is why it is
schedulable at all where acts 1 and 2 are not: **rolling back re-mints
backwards.** `recertify` signs against whatever boundary is installed, in either
direction, so after a rollback you run the preflight and then `recertify`
again, exactly as you did going forward. An operator who declines lands on
`uncertified` — honest, non-blocking, and reversible whenever they find the key.
Acts 1 and 2 have no symmetric certificate-style remedy: the restored v2 agent
refuses a v3-stamped manifest and a v3-stamped repository outright. The PMPro
rollback is therefore a bundle rollback to its prior manifest plus ordinary
recompile/re-pin, not a compatibility fallback.

So the practical certificate rule for a cohort is: **after step 5 a rollback
costs one more `recertify`.** Plan the cohort so that step 4's post-verify
happens before step 5. Separately, any site pinning one of the deliberately
migrated v3 adapters already owes the bundle-and-repin rollback described above.

---

## Rollback, concretely

1. Run the preflight against the sites you are rolling back, from the release
   you are rolling back **to**. Same command, same comparison, opposite
   direction.
2. Redeploy the prior `agent recovery` archive. All embedded surfaces, one
   atomic swap. Never replace only part of `agent`: its assembled
   `adapter-library/` travels inside the same tree by design, and a mixed state
   refuses at load.
3. Re-mint symmetrically: `wprism adapter recertify <repo> --secret-key-file=<f>`
   on every site where you ran step 5 going forward.
4. Re-project: `wprism release --spec-v3` is not the verb for this direction, but
   the same surfaces move back — read the preflight's movement rows and
   recompile the artifacts they name.

Beyond retaining the prior bundle, **forward-fix is the only other path**, and
that is acceptable precisely because of the window: a defect in a v3-only
section is refused **by name, per adapter**, rather than taking a library down.

---

## What this runbook does not cover

- **Re-stamping any shipped manifest to `spec_version: 3`.** Excluded from the
  flag day entirely (§ v3.12). The 16 migrate individually after G3, each
  moving only its own digest and only for the sites that pin it.
- **Populating the platform trust root.** `adapter-authorities.json` stays
  `{"keys":{}}` through the flag day. Issuing one key is gate G4's decision —
  [trust-enrollment.md](trust-enrollment.md) carries the ceremony, the vetting
  posture, and G4's conditions with their current truth values.
- **Opening the executable lane.** Gate G5, and the default answer there is no.

---

## The evidence behind this page

| Claim | Where it is proven |
|---|---|
| No shipped adapter digest, no shipped `manifest_hash`, no manifest byte moved | `sandbox/tests/offline/policy/regress_spec_v3_digest_neutrality.php`, against fixtures frozen pre-bump |
| A certificate-holding site DOES move its `manifest_hash` and `revision_hash`, and its held artifact refuses | `sandbox/tests/offline/guards/regress_spec_migration_rehearsal.php` — measured per site, `held` vs `MOVED` |
| The preflight predicts that split per site, before the bump | `sandbox/tests/offline/cli/regress_migration_preflight.php`, cross-checked against the rehearsal's observed set |
| A statement minted under one signature domain never verifies under another | `sandbox/tests/offline/adapter/regress_cross_root_replay.php` |
| A hand-mixed bundle refuses at load | same suite, PART 5 |
| The window accepts a v2 manifest and a v2 repository | `sandbox/tests/offline/policy/regress_spec_window.php`, `sandbox/tests/offline/repository/regress_repository_compiler.sh` |
| `recertify` is idempotent, all-or-nothing, and blocks by key name | `sandbox/tests/offline/cli/regress_spec_migration_verbs.php` |
| `release --spec-v3` journals the prior pins before emitting any new one | same suite, PART 5 |
| A site driven A→B→A returns to its exact pre-flag state | `sandbox/tests/offline/guards/regress_spec_migration_rehearsal.php` |
| A stale certificate degrades rather than refusing its source | `sandbox/tests/offline/adapter/regress_site_adapter_certification.php` |
