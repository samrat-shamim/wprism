# Running a coverage cohort

A **cohort** is a named batch of adapters, chosen off the top of a measured
demand rank, authored with the toolchain, and then *graded by whether the
coverage ratio moved*. That last clause is the whole guide. Adapters existing
is not the result; adapters existing is the input.

Read this when you have `duo census` output in front of you and you are about
to decide what to build next. The per-adapter craft — what a manifest declares,
how to classify a surface, what a disposition has to say — is
[adapter-authoring.md](adapter-authoring.md); this page never restates it. This
page is about the loop *around* those steps: choose, build, adopt, re-measure,
and read the answer honestly when it is not the answer you wanted.

## The exit criterion, stated first

> A cohort that ships every adapter it planned and does not move the ratio is a
> **finding**, not a success.

That sentence is not exhortation. It is a value in a document:
`duo census --baseline=` emits `cohort.verdict`, and the word for that outcome
is `shipped_without_movement`, published beside a `finding` object that says so
in prose. There is no way to run the instrument, ship a cohort, move nothing,
and have the output read as a win.

The reason the criterion is written this way is the failure mode it exists to
catch. An adapter that exists in the library but that no site *pins* contributes
nothing to any site's coverage — it sits at funnel stage `adapter_unpinned`, and
the surfaces it would claim are still residual on every site that installed the
plugin. A program measured on "adapters shipped" hits its target and misses its
goal, and every number on the page agrees with it.

## The instrument

```sh
# What is, now.
duo census --dir=<inventories/> --format=json > current.census.json

# What moved, against a census you kept before the cohort.
duo census --baseline=before.census.json --current=current.census.json

# Or in one step: read the baseline from disk, measure the current side here.
duo census --baseline=before.census.json --dir=<inventories/>
```

`--current` and `--site`/`--dir` both name the current side, so passing both is
refused rather than silently preferred. Use `--current` when you already hold the
current census — above all when it was measured against a **manifest library this
checkout no longer has**, which a checkout cannot re-derive. Use `--dir` when you
want the present measured here and now, against the library you are shipping.

Neither form is the one that "works across a flag day". A kept baseline document
is readable whichever engine wrote it; `comparability.engine` records the move
instead of refusing it, because a program worth re-baselining usually crossed one.

The output is a `duo-cohort-rebaseline/v1` document with five blocks:

| block | the question it answers |
|---|---|
| `comparability` | Do these two censuses describe the same estate at all? |
| `coverage` | Both ratios and the difference, in ppm. |
| `funnel` | Movement per stage: `no_adapter` → `adapter_unreviewed` → `adapter_unpinned` → `adapter_pinned`. |
| `attribution` | Per plugin slug: which adapter's claim moved which surface ids. |
| `cohort` | What shipped, the verdict, and the finding when there is one. |

Exit status stays 0/1/2 — 0 answered, 1 refused, 2 usage. A finding is an
**answer**, so it does not get its own exit code; a job that wants to gate on it
reads `cohort.verdict`, which is a closed six-word vocabulary precisely so that
it can.

### The six verdicts

| verdict | what it means | carries a finding |
|---|---|---|
| `coverage_moved` | A cohort shipped and the ratio rose. | no |
| `adoption_only` | The ratio rose and no adapter was added — pins moved it, not new code. | no |
| `shipped_without_movement` | A cohort shipped and the ratio did not move. | **yes** |
| `coverage_regressed` | The ratio fell. | **yes** |
| `no_cohort` | Nothing shipped and nothing moved. | no |
| `unmeasured` | One side measured no surfaces, so there is no ratio to difference. | no |

`adoption_only` is the distinction a bare ratio cannot make, and it is worth its
own word: a quarter where the number rose because a fleet finally pinned last
quarter's adapters is a real result, but it is not evidence that this quarter's
authoring worked.

### Attribution keeps four surface movements apart

A net ratio hides things. Per slug, `attribution.moved[]` separates:

- `surfaces_claimed` — was residual, is now credited to an adapter. The only one
  a cohort may take credit for.
- `surfaces_regressed` — was credited, is residual again. Reported with equal
  weight, because a disposition narrowed in the same release that shipped a
  cohort is exactly the movement a net number conceals.
- `surfaces_appeared` — new residual, present in neither list before. New demand.
- `surfaces_resolved` — residual that left the estate entirely. Never credited to
  an adapter.

A slug whose adapter, funnel stage, residual surfaces and pin count are all
unchanged gets no row; it is added to `attribution.unchanged` instead, so
"nothing moved" arrives as a number rather than as an absence you have to infer.

These four are the movements of the **residual**, and that boundary is
deliberate. A surface that arrived already credited, or that left the estate
while credited, is the site's *data* moving under a claim that did not move — no
adapter may be credited or blamed for it, so it is not attributed to one. Those
show in `coverage.delta.total` and `coverage.delta.covered`, which is the block
about the denominator.

### Comparability is published, never assumed

`comparability.class` is computed from the opaque site labels alone:

- `same-estate` — the same labels on both sides. Only here is
  `cohort.attributable` true, and only here does the delta mean "the library and
  the pins did this".
- `population-changed` — sites were added or removed, so the delta mixes cohort
  effect with population change. The numbers are still published; nothing calls
  them a cohort's work.
- `disjoint-estate` — no shared label at all. This is two different estates, and
  the document says so.

Two things **refuse** instead of being classified, because they change what the
ratio *means* rather than what it measures: a different `library.site_mode` (the
platform boundary decides which submissions are eligible, so the denominators
were built by different rules), and a document that is not a census or is missing
a block the delta reads. A different **engine** does not refuse, for the reason
above: an instrument that would not look across a flip could not answer the only
question anyone asked of it.

## The label you are measuring under

Read `basis.sample_class` before quoting any ratio. `duo census` publishes one of
`one-site`, `narrow` or `fleet` from the eligible submission count, and at or
below the narrow bound it says so in as many words:

```
basis: this census folds 3 eligible submissions, at or below the 5-site narrow bound: read the rank as one estate's demand, not a fleet's
```

That is the **core-estate label** — the limitation this program's own G0 opened
with. A narrow census still ranks demand correctly *for that estate*; what it
cannot do is tell you that the estate's demand is the ecosystem's. A cohort
ranked off a narrow census and then found to have shipped without movement has
two candidate causes, and the label is what keeps the second one on the table:
the pins never landed, or the rank was never the fleet's to begin with.

The excluded population is published beside the eligible denominator rather than
dropped — a multisite submission is excluded because the platform claims
single-site, and it appears in `population.excluded` with the reason.

## The loop

Nine steps. Each verb below exists today; the ones that are dev-side tooling
rather than operator verbs are marked as such.

### 1. Rank — `duo census`

```sh
duo census --dir=inventories/ --health=proposals.json --limit=20
```

The rank is `sites installed × surface no reviewed adapter covers`, so the top
row is the plugin costing the most sites the most surface. Take the cohort off
the top of it, not off a conversation. `--health` folds in the derived document
`duo adapter proposals --format=json` writes, which ranks the *other* kind of
work — adapters that exist and have fallen behind their plugin's releases —
beside the adapters that do not exist yet. Those compete for the same week.

Keep this document. It is the baseline for step 9. A census is in principle
re-derivable — the same inventories folded against the same checkout give the
same bytes, which is how the baseline below is verified — but that needs both
halves preserved, and the inventories are per-site captures nobody re-takes. In
practice the kept document is the only baseline you will have.

### 2. Probe — `wp duo adapter-probe`

```sh
wp duo adapter-probe --format=json > probe.json
```

Run against an environment that actually has the plugin installed and exercised.
The probe reads the named unprefixed tables and the target's own schema
(`SHOW COLUMNS`, `SHOW INDEX`, `information_schema`) and answers a closed
question set. It carries no row values and promotes nothing.

### 3. Draft — `duo adapter-draft`

```sh
duo adapter-draft <site-repo> --name=<n> --evidence=probe.json --out=manifests/<n>.json
```

The draft proposes rules from the repository's captured `state/**` plus the
probe's live column types. It is a proposal: the `plugin`, `version_range` and
evidence notes are still yours to write, and
[adapter-authoring.md](adapter-authoring.md) is where the craft lives.

### 4. Boundary — `duo adapter boundary`

```sh
duo adapter boundary --releases=<n>.releases.json --anchor=<version> \
                     --outcomes=<n>.outcomes.json --format=json
```

The certified `version_range` is the claim most likely to be written by guess.
This bisects it from recorded evidence instead: `--releases` is a pinned release
list with URLs and sha256s (nothing here reaches the network), `--anchor` names a
release believed green, and the search bisects outward. One probe is a full pair
round trip, so this is a **planner**: exit 3 names the one release to probe next,
exit 0 emits the finished document, and `sandbox/bin/adapter-boundary.sh` is the
loop between. It never writes a manifest — the range and its byte-equal
restatement in `manifests/dispositions/<n>.json` stay one reviewed human edit.

At cohort scale, `duo adapter proposals` is the scheduled job around the same
planner across every adapter in a ledger directory.

### 5. Vectors — record once, replay forever *(dev-side)*

```sh
CONF_RECORD_VECTOR=<out.json> bash sandbox/conformance/run.sh <manifest-name>
```

A live conformance sweep proves the round trip against a disposable pair. That
pair is budgeted, so it cannot be your iteration loop. Recording a
`duo-conformance-vector/v1` document during a sweep that *already passed* turns
the one live proof into an offline suite that replays it on every gate run
afterwards. The replay says a deliberately weaker word than the sweep did —
`vector_replayed`, not `conformance_verified` — so a recording cannot be mistaken
for a re-verification.

### 6. Kit — `tools/adapter-kit.php` *(dev-side)*

```sh
php tools/adapter-kit.php --assemble=<dir> --adapter=<n>
```

Hand this to whoever is writing the adapter if they are not working inside this
repository. It assembles the generic half of the test estate — the shared
`$wpdb` fake, the check harness, the conformance entries — by reading the live
files, so a kit is by construction a function of the tree at the moment it was
built. There is no second copy of the harness in the repository that could
receive a fix the original never got.

### 7. Certify — `duo adapter certify`

```sh
duo adapter certify <site-repo> --name=<n> --secret-key-file=<f> \
                    --reason='<why this adapter is trusted here>' [--pin --adopt-scope]
```

This is the step that makes the claim *yours* rather than the library's, for a
site-installed adapter. `--pin --adopt-scope` certifies and writes the pin in one
act. Key handling, reviewer tiers and revocation are
[trust-enrollment.md](trust-enrollment.md); do not mint a key inside a site
repository, because a site repository is committed and published.

### 8. Bulk adopt — `duo adapter adopt-scope`

```sh
duo adapter adopt-scope <site-repo> <site-repo> … --name=<n> --dry-run
duo adapter adopt-scope <site-repo> <site-repo> … --name=<n>
```

**This is the step that decides whether the cohort moves the metric.** Everything
before it produces an adapter; this is what makes sites pin it. One reviewed
invocation writes the post types and taxonomies the adapter declares authored
into every named `site.duo.json` that had decided nothing about them, instead of
N hand edits. Every repository must already resolve the adapter, and the whole
set is refused unwritten if one does not. A class a site *recorded* is printed
and left alone — overriding one is a per-site act.

Run `--dry-run` first. It reports the same plan and writes nothing.

### 9. Re-measure — `duo census --baseline=`

```sh
duo census --baseline=before.census.json --dir=inventories/
```

Collect fresh inventories from the same labelled sites, then difference. Publish
the output with the residual named. If the verdict is `shipped_without_movement`,
that is the result — write it down as the result.

## When the cohort ships and nothing moves

This is the outcome the loop is built to make visible, so here it is rendered:

```
rebaseline: 3 -> 3 labelled site(s) (same-estate)
comparability: both censuses fold the same 3 labelled site(s), so every delta below is a delta of one estate over time
library: 16 -> 16 adapter(s), 16 -> 16 reviewed, coverage oracle MOVED
coverage: 45.0% -> 45.0% (delta +0 ppm / +0.0pp) over 2000 -> 2000 surface(s)
funnel: no_adapter=2->0(-2) adapter_unreviewed=0->0(+0) adapter_unpinned=0->2(+2) adapter_pinned=0->0(+0)
moved synth-beta: adapter=none->synth-beta (adapter_added) claimed=0 regressed=0 appeared=0 resolved=0 pins=+0 [no_adapter->adapter_unpinned]
moved synth-gamma: adapter=none->synth-gamma (adapter_added) claimed=0 regressed=0 appeared=0 resolved=0 pins=+0 [no_adapter->adapter_unpinned]
attribution: 2 slug row(s) moved, 0 unchanged
cohort: 2 adapter(s) added, 2 slug(s) newly covered, 0 newly reviewed, 0 newly pinned; 0 surface(s) claimed, 0 regressed
verdict: shipped_without_movement — …
FINDING cohort_shipped_without_movement: 2 adapter(s) newly cover 2 plugin slug(s) and the fleet coverage ratio did not move: this is a FINDING that re-opens G0, not a cohort success
```

Every adapter the cohort planned shipped. The funnel shows both plugins leaving
`no_adapter`. And `claimed=0` on both rows, because both landed in
`adapter_unpinned` and an unpinned adapter covers nothing.

The finding line is printed **last** and is never truncated by `--limit`, which
bounds the attribution listing above it and nothing else: the one row a shortened
view must never drop is the one that says the cohort failed. It goes to stdout,
not stderr — it is the answer the run was asked for, and a caller redirecting
stdout to a file must find it there.

Two remedies, and the document tells you which:

- **`funnel.adapter_unpinned` grew.** The adapters exist and no site pins them.
  Go back to step 8. This is an adoption problem, and it is closable.
- **The pins are already in place and the ratio still did not move.** The cohort
  was ranked against demand the estate does not actually carry. The demand rank
  is what has to be re-taken — re-read `basis.sample_class`, widen the census, and
  choose again. This re-opens the ranking decision, which is the honest meaning of
  "re-opens G0".

`coverage_regressed` carries its own finding for the same reason. A narrowed
disposition, a dropped pin and a newly-installed plugin are three different
causes with three different remedies, and `attribution.moved[].surfaces_regressed`
is what tells them apart.

## This program's own re-baseline

The adapter-decentralization program measured itself with this instrument, and
publishes the result here because a runbook whose worked example is invented is
not a runbook.

**The estate.** `sandbox/tests/fixtures/census/core-estate/` is a fixed reference
estate of three labelled single-site submissions. It is not a managed fleet and
nothing here calls it one. It is admitted by G0's own limitation clause — the
narrow-sample label above — and what it buys is exactness: held byte-constant
across both measurements, every difference between the two censuses is a
difference in the **library** and in nothing else.

**The baseline.** `sandbox/tests/fixtures/census/g0-baseline.census.json` was
produced by the engine of its own day — `spec_version: 2`, `agent_version: 0.5.0`
— at commit `f99f6712`, the commit that first shipped `duo census` and therefore
the earliest moment this program could measure itself at all. It is reproducible,
which is what makes it admissible as a baseline rather than a re-print:

```sh
git archive f99f6712 | tar -x -C <tmp>
php <tmp>/cli/duo census --dir=sandbox/tests/fixtures/census/core-estate --format=json \
  | diff - sandbox/tests/fixtures/census/g0-baseline.census.json
```

**The result**, measured against the library this checkout ships:

```
rebaseline: 3 -> 3 labelled site(s) (same-estate)
library: 16 -> 17 adapter(s), 16 -> 17 reviewed, coverage oracle MOVED
coverage: 24.5% -> 24.5% (delta +0 ppm / +0.0pp) over 1075 -> 1075 surface(s)
funnel: no_adapter=1->1(+0) adapter_unreviewed=0->0(+0) adapter_unpinned=2->2(+0) adapter_pinned=2->2(+0)
attribution: 0 slug row(s) moved, 5 unchanged
cohort: 0 adapter(s) added, 0 slug(s) newly covered, 0 newly reviewed, 0 newly pinned; 0 surface(s) claimed, 0 regressed
verdict: no_cohort
```

**The adapter-coverage delta of this program is exactly zero**, and the verdict is
`no_cohort` rather than `shipped_without_movement`: no adapter shipped, so this is
not a cohort that failed — it is not a cohort. The program built grammar and trust
machinery (a spec flip and its acceptance window, per-subject dispositions,
certificate and authority wire formats, staged engine features, the census, the
probe, the boundary bisector, the kit, the vectors) and did not add adapters. The
honest number for that work is zero movement in adapter coverage, and asserting
*that* is what the instrument is for.

Two facts make the zero trustworthy rather than merely small:

- **The coverage oracle moved exactly once, and not for a reason that adds
  coverage.** `library.surfaces_sha256` is the content address of every reviewed
  claim's derived surface set. It was byte-identical across the program itself —
  including across the disposition split, which is exactly the invariance that
  change asserted — and then moved with #561, which promoted
  `the-events-calendar` from `experimental` to `certified` and rewrote its
  reviewed entry. That is the useful reading of this line: a moved oracle says a
  claim changed, not that coverage grew. Here it changed and the delta stayed at
  zero, because TEC's surfaces were already claimed against this estate.
- **The residual is named, and it did not move either.** The top demand row of
  the core estate is `wp-rocket`, at funnel stage `no_adapter`, with no covering
  adapter before the program and none after it. That is the work this program did
  not do, and a zero delta is only honest if what it left behind is on the page.

`sandbox/tests/offline/cli/regress_cohort_rebaseline.php` re-proves all of this on
every gate run. If a later change moves the shipped library's coverage of that
estate, the suite fails deliberately — the remedy is to read the published delta
and record the new result, never to loosen the assertion.

## What this does not measure

- **Nothing here is signed, and nothing here is fleet-wide truth.** A census is a
  fold of documents that happened to be on one machine. It carries the labels and
  counts its submissions disclosed, no option value, no site URL, and no claim
  that the submissions are representative.
- **It cannot tell you an adapter is *correct*.** Coverage counts surfaces a
  reviewed claim covers. Whether the claim is right is what the conformance
  sweeps, the vectors and the disposition review answer — see
  [capabilities-and-limits.md](capabilities-and-limits.md) for exactly what a
  status in the matrix does and does not mean.
- **It does not attribute across a changed estate.** `population-changed` and
  `disjoint-estate` publish their numbers and withhold the attribution, on
  purpose.
