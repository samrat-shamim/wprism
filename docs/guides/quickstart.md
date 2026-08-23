# Quickstart: getting a site under Duo

Start by asking what Duo can honestly do with this site. `duo assess <env>` is
read-only, refuses nothing, mutates nothing, and answers that question one
WordPress surface at a time — and it answers it *before* you have committed to
a baseline. It needs the agent to be reachable, which is the one thing adoption
provides, so the order for a new site is:

**adopt (deliver the agent) → assess (decide) → init (commit to a baseline).**

On a Docker or local target whose control plane already carries the agent,
`duo assess` is the very first Duo command you run. [assess.md](assess.md) is
the guide to reading its output; this page is how you get there and what
happens after.

`duo init <env>` is the shipped first-run path once the Duo agent is reachable
on an existing WordPress target. How the agent gets there is transport-specific:

1. **SSH:** `duo adopt` installs the agent, manifest library, and recovery
   runtime first; then `duo init` discovers and captures the site.
2. **Machine-local:** an untracked, explicit bootstrap opt-in lets `duo adopt`
   prove and initially install that same control plane before `duo init`. It
   refuses when a Duo control plane is already present; installed-target
   updates use the existing environment update path.
3. **Docker:** the agent must already be installed or mounted; `duo init` uses
   that authenticated transport directly. Init never silently delivers it.

Init is not a WordPress installer. It starts from a working site and a reachable
agent, proposes the exact managed boundary without mutation, requires explicit
confirmation, and then creates separate code and canonical state/media
baselines. Unsupported extensions, custom code layouts, stale repository
payloads, or non-current certification evidence keep the proposal red.

## Before you start

The machine that runs `duo` needs PHP 8+ with Sodium, plus `ssh`, `scp`, and
`tar` if any environment uses the SSH transport. `duo` itself is dependency-
free: no composer, no vendored packages, and no WordPress on the orchestrator
host.

An SSH target needs PHP 8+ with Sodium and a working `fsync()`, a working `wp`
command, `tar`, and an installed WordPress. Adoption itself ships files rather
than cloning a repository, but the subsequent `duo init` path also requires a
working `git` binary on the target: init verifies or creates the target-owned
Git worktree before publishing its baseline. The configured `repo_path` itself
must already be an ordinary directory reached without symbolic-link ancestors;
SSH or authorized local adoption creates it, while Docker control-plane setup
or the site's bind mount must create it before init. Init binds that exact directory before
reading or writing repository children and refuses if its identity changes.
The SSH account must be able to
write WordPress's actual `WPMU_PLUGIN_DIR` (discovered through the target's own
`wp eval`, never guessed from `wp_path`) and the environment's configured
`repo_path`.

The full prerequisite and safety contract is
[docs/adoption.md](../adoption.md); this guide is the narrative around it.

## Path A — adopt an existing site over SSH or an authorized local transport

SSH always exposes the explicit transfer mechanism. A local environment may
expose it only through a loader-proven, untracked machine-local opt-in; Docker
does not infer bootstrap authority from a bind mount or shell access.

### 1. Describe the environment

Duo searches upward for the committed `site.duo.json`, but inside Git accepts
it only at the current worktree root, then reads a gitignored, machine-local
`.duo-envs.json` overlay beside it. Before a site file exists, the automatically
discovered overlay must be at the current Git worktree root. A nested registry
or overlay is refused rather than allowed to shadow environment authority;
`--envs-file` is the explicit overlay trust override. The overlay replaces a
same-named entry *whole* — there is no per-key merge — so for any environment
exactly one file is the source of truth. Anything secret or machine-specific
belongs in the overlay.

```json
{
  "envs": {
    "production": {
      "transport": "ssh",
      "host": "deploy@wp.example.com",
      "wp_path": "/var/www/html",
      "repo_path": "/home/deploy/site-repo"
    }
  }
}
```

`repo_path` is required on every transport and is always the site repo's path
*as seen from inside that environment*. See
[cli/README.md](../../cli/README.md#the-environment-registry) for the
per-transport required keys and the rollback/recovery options an SSH entry may
additionally carry.

For a local target, put the full entry in the untracked `.duo-envs.json`
overlay (not `site.duo.json`):

```json
{
  "envs": {
    "dev": {
      "transport": "local",
      "wp_path": "/srv/wordpress",
      "repo_path": "/srv/site-repo",
      "bootstrap": {"format": "duo-local-control-plane/v1"}
    }
  }
}
```

`duo driver-capabilities dev --operation=adopt` checks only that closed local
authorization and never contacts the target. `duo adopt dev` then isolates
ordinary plugin/theme/MU loading, proves WordPress and a safe disjoint
filesystem topology read-only, and refuses with remediation before creating
an archive or target path when that proof is red.

### 2. Check, then adopt

Run these from a Duo source checkout whose `cli/`, `agent/`, `manifests/`, and
`recovery/` directories are the release you intend to install — adoption ships
*those exact trees*, so the checkout you invoke from is the version the target
gets.

```sh
cli/duo doctor production   # expected to report the agent/repo missing first
cli/duo adopt production
```

The first command is supposed to fail. Running it anyway is worth the ten
seconds: it tells you whether the transport, the WordPress install, and the
paths are right *before* adoption starts moving files, and it gives you a
before/after pair for the same nine checks adoption ends with.

`duo adopt` verifies reachability, discovers `WPMU_PLUGIN_DIR`, installs the
agent, loader, manifest library, and recovery runtime, creates the protected
control root and stable target identity, creates `repo_path/site.duo.json`
**only when it is absent**, then proves in fresh wp-cli processes that the
installed agent version matches this checkout exactly and that the policy
loads. It ends by running the same `duo doctor` checks, and succeeds only when
every blocking one passes.

Over SSH, re-running `duo adopt` is the update mechanism: it replaces the
agent and manifest trees with the ones beside the invoking CLI and leaves the
site's policy untouched. The privileged machine-local bootstrap is
initial-only and refuses a second adoption rather than racing an installed
recovery authority.

### 3. Assess before you initialize

```sh
cli/duo assess production
```

Adoption made the agent reachable; assessment is what tells you whether
initializing is a good idea. One read-only run composes doctor, the adoption
and initialization probes, the target's own inventory, the capability report
per operation, coverage, the review queue and the adapter catalog into one row
per WordPress surface, each carrying its state class, handling, technical
readiness, certification provenance, effect containment and effect recovery
semantics, plus the smallest safe next action for every gap.

Nothing here writes to the target, and a site full of blocked surfaces is a
**successful** assessment that exits 0 — an assessment is not a completeness
claim. What you are looking for is whether the surfaces you actually care about
read `Ready` or `Ready with conditions` for the operations you intend, and
whether the unknown section is a queue you are willing to work through.

Assess also writes `.duo/contract/<env>/proposed.json` in your local site
repository — per environment, because the proposal describes one environment,
while the contract it becomes is one per site. That is the beginning of the
reviewed application contract that `duo release`, `duo verify` and `duo recover`
all read later; you do not have to deal with it now, and
[assess.md](assess.md#record-the-decision-the-application-contract) picks it up
when you do.

If the assessment says the site is not a fit, you have learned that before
creating a baseline, which is the whole reason this step is here rather than
after step 7.

### 4. Review and confirm the first baseline

```sh
cli/duo init production
```

Init reads WordPress, PHP, database, active plugin/theme, certified adapter,
standard code-root, media, and value-redacted risk facts through the target
agent. The proposal is read-only and content-addressed. Confirmation sends its
digest—not a host-authored config—and refuses if any discovered fact changes.
Use `--yes` only for automation that has already preserved the rendered
proposal.

`duo init` also **classifies the code half**, and Git never carries third-party
code: each active plugin and theme either LOCKS — against its published wp.org
release when the installed bytes hash-match it, or against an archive you
imported on this host with `duo code-import <archive.zip>` (a premium plugin,
a vendor theme) — and is declared in `code/duo-code.lock.json` and kept out of
Git, or is declared the site's own code with `--first-party=<root>/<slug>` and
carried in Git by that declaration. A component that is neither blocks the
proposal with both remedies named; there is no "vendor it anyway".
Classification runs on the orchestrator host, never on the target, and the
proposal prints every component with its classification and the reason for it
before you confirm — the classification is inside the digest, so a stale
`--confirm` cannot apply one you did not read. `--offline` contacts no
registry: wp.org components lock only from the host cache. See
[code-updates.md](code-updates.md#the-code-half-git-never-carries-third-party-code).

Before confirming, quiesce every non-Duo writer to the repository namespace,
including package managers, self-updaters, Git/shell automation, and processes
that can write `.git`, `code/`, `media/`, `state/`, or `state.capture*`. Init's
database lease and capture lock serialize Duo processes; they do not turn the
v0 PHP filesystem layer into an adversarial race sandbox. Keep that exclusion
in place until init succeeds or any retained recovery evidence is resolved.

On success the target repository is Git-ready and contains independent
`site.duo.json`, `code/wp-content`, `state`, and content-addressed `media`
contracts — plus `code/duo-code.lock.json` when the split locked at least one
component. The locked components' bytes are on disk and compile normally at the
target; they are simply not in Git, so a fresh clone needs the materialization
step in [code-updates.md](code-updates.md#the-materialization-step) before its
first compile. Duo refuses that compile by name (`code_component_unresolved`)
rather than producing a shrunken payload. Init finishes through ordinary `duo status`. Its clean statement is
limited to the selected managed adapters; unsupported site state is never
silently promoted into that claim, and the initial state capture is not a
promotion rollback checkpoint.

### 5. Record what must not move

Before capture, record checksums for state that has to stay local to this
runtime: plugin tokens, caches, derived indexes, custom-table rows. The exact
query is plugin-specific — keep both the command and its output with the change
record. You will re-run it after capture, and the comparison is the actual
acceptance test.

### 6. Measure, then review

```sh
cli/duo coverage production --format=json > production-coverage.json
cli/duo pending production
cli/duo classify production --export-batch=production-review.json
```

`coverage` is a survey, not a green gate: it never blocks anything and never
appears in the review queue. Read it against the inventory you expect. A plugin
with uncovered state needs a manifest or table schema — it cannot be made safe
by classifying an unrelated option.

Its four option buckets sum to `total`. A **declared** name is never invisible,
whatever its class: an option an adapter declares `env`, `runtime` or `derived`
is *declared and excluded*, counted under `declared-excluded` (broken out per
class in `--format=json`), and carries no action — the adapter models it and
says Duo must not version it. `invisible` means the opposite: **no rule from any
source** — no exact declaration, no namespace claim, no pattern, no dynamic
prefix. Those are the names nothing on the site can see, and the only ones the
count is asking you to do something about.

`pending` is the review queue. Its proposals come from observed evidence and
are never guessed; an item with no proposal prints `-` rather than a
plausible-looking default.

For an aged site, batch review is the sane path — historical writes predate the
journal, so most items will have no proposal to accept. The exported artifact
is value-redacted: it carries the evidence, never live values.

```json
{
  "section": "options",
  "key": "legacy_banner",
  "class": "authored",
  "ref": null,
  "cast": null,
  "allow_secret": false,
  "autoload": "preserve",
  "required": null
}
```

Fill every `decisions[].class`. The vocabulary is explained in
[capabilities-and-limits.md](capabilities-and-limits.md#the-five-classes); the
short form is `authored` only for portable intent, `runtime` for
environment-local operational state, `derived` for state a declared
regeneration path rebuilds, `env` for separately provisioned per-environment
values, and `managed` for lifecycle-managed options.

An **options** row needs one more answer, and only the one its class reads:
`autoload` when the class is `authored` or `managed` (`preserve` replays the
source row's own flag; `yes|no|auto|on|off|auto-on|auto-off` pins it), and the
boolean `required` when the class is `env` (`true` if an operator must
provision the value on a fresh environment). Both are `null` on export and
neither is ever guessed — the same posture as `pending`'s proposals.

### 7. Apply the batch — then look again

```sh
cli/duo classify production --apply-batch=production-review.json
cli/duo pending production
```

The artifact is bound to the environment and to the exact pending evidence by
SHA-256. If a write landed after export, apply refuses *before* touching
`site.duo.json` — export and review a fresh batch. Partial batches refuse for
the same reason, and so does a row whose class needs `autoload` or `required`
and does not have it: the refusal names the row and the field, and no remote
write is opened.

**The capture gate is an empty queue, not a successful batch.** One layer of
decisions routinely exposes another, so keep looping until `duo pending` prints
`review queue is empty`.

### 8. Capture, then check the checksums again

```sh
cli/duo capture production
```

Then re-run the runtime checksums from step 5. **Any changed runtime checksum
is a failed adoption, even when capture exits 0.** Capture is supposed to
observe the site, not to perturb it; a moved checksum means something in the
classification is wrong, and a green exit code does not overrule that.

Keep the source repo as the canonical artifact. Do not copy the WordPress
database to another host to "prove" portability — that proves the database
copied, which was never in question.

## Path B — a Docker site or a local site without bootstrap authority

Install or mount the Duo agent and manifest library through that environment's
own control-plane setup — this path has no `duo adopt` step, so nothing else
will deliver it. "The agent is already there, so the assessment costs one
read-only run" is true, but `duo assess` still needs a repository to run
*against*: it walks upward from the current directory for `site.duo.json` and
refuses with `[local_site_repo_missing]` — "run this command from inside the
site repository that holds site.duo.json" — the moment it finds none. Path A
gets that file as a side effect of `duo adopt` (step 2 above, "creates
`repo_path/site.duo.json` only when it is absent"); Path B has no such step,
so give assess a repository first, then assess, then init:

```sh
mkdir -p <repo>
cat > <repo>/site.duo.json <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "term_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp sandbox/site-repo.gitignore.template <repo>/.gitignore
git -C <repo> init
```

That JSON is not a placeholder — it is byte-for-byte the adoption seed
`InitPlanner` compares a repository's `site.duo.json` against
(`agent/src/Init/InitPlanner.php:953-1060`, `is_adoption_seed()`/
`existing_config()`'s `$seed`): the one state in which `duo assess` previews
init's own proposal instead of the seed's trivial `core`-only pin set, and the
one state in which `duo init` still owns the file and will recompute and
republish it rather than refuse. The `.gitignore` goes on before anything else
touches the directory because `assess` itself — not only `init` — starts
writing scratch here: its `.duo/contract/<env>/proposed.json` write (step 3 above)
needs `/.duo/` ignored from its very first run. `git -C <repo> init` makes
`<repo>` its own Git worktree root; skip that and, if `<repo>` was created
inside some other project's checkout — the easy mistake on a first
experiment — its `site.duo.json` is refused as a nested registry rather than
trusted, by both `assess` and `init`.

Add the environment next to it, in an untracked `.duo-envs.json` — on this
path, only there. `site.duo.json` can carry a shared `envs` key in general,
but the seed comparison is against the *whole decoded file* (the only
things `existing_config()` sets aside are source-bearing manifest pins and
the scope rules those pins wrote — the paragraph below), so an `envs` key
inside `site.duo.json` makes the repository operator-owned and `duo init`
refuses it as `existing_configuration`. Share
environments through the site file only on the deliberately-manual path
below, after deciding init is not for you:

```json
{
  "envs": {
    "dev": {"transport": "local", "wp_path": "/var/www/html", "repo_path": "/home/me/site"},
    "e1":  {"transport": "docker", "compose_file": "sandbox/docker-compose.yml",
            "service": "cli-e1", "repo_path": "/siterepo"}
  }
}
```

Then assess before you initialize — nothing here writes to the target, and a
site full of blocked surfaces is still a successful assessment:

```sh
cli/duo assess dev
cli/duo init dev
```

The target itself needs WordPress, WP-CLI, Git, and a standard supported
`wp-content` layout; `repo_path` — a writable, pre-existing ordinary
directory with no symbolic-link ancestor — is the one piece of that Duo will
not create for you, on any transport; you already did, in the step above. Init
may create the Git worktree pieces and Duo contracts it owns inside that seed
directory, but it does not install WordPress or deliver the agent, and it
refuses before confirmation when those prerequisites or the certified managed
boundary are not present. An adoption seed that already carries explicit
`{name, source: "site"|"plugin", digest}` pins — what `duo adapter certify
--pin` and `duo adapter pin` write for an operator-authored or overriding
adapter — is still the seed: init recomputes and republishes those pins
exactly, so certifying first and initializing second is the intended order.

Those two commands write a second thing, and it is part of the seed too: a
pin is the site's scope opt-in, so `--pin` also records
`policy.scope.post_type.<type>` / `policy.scope.taxonomy.<taxonomy>` =
`{"class": "authored"}` for every surface the adapter declares authored that
the file had not already decided (it prints each one). A rule that a pinned
site or plugin adapter accounts for that way travels with its pin and leaves
the repository a seed. Nothing else under `policy.scope` does: a `runtime`
rule, a rule for a surface no pinned adapter declares, a rule for one the
adapter classifies itself, or one you added by hand is a policy decision, and
the repository is operator-owned again. Init then republishes that opt-in
*once*, as the ordinary flat `policy.post_types` / `policy.taxonomies` entry
it writes for every adapter it selects — the same file you would have got by
installing the adapter before init — and not as a scope rule beside it: a
site scope rule outranks every manifest, so keeping both would let a stale
`authored` rule go on classifying a surface its adapter had since
reclassified.

### When you need a policy the initializer cannot propose

Hand-author `site.duo.json` instead of taking init's proposal only when you
intentionally need something init cannot discover on its own. The seed above
is the exact fork line, not just a starting point: a repository whose
`site.duo.json` is byte-identical to it is still init-owned no matter who
typed the bytes, `term_meta` included — `duo init` accepts and republishes it
unchanged. Pin a real manifest, edit `policy`, or drop one key, and the
repository becomes operator-owned instead: `duo init` refuses with
`existing_configuration` — "the repository already has a non-seed Duo
configuration" — correctly, because by then it is one. Continue that
repository with `duo doctor`, `duo pending`, `duo classify`, and
`duo capture` instead of `duo init`; a fresh site's first queue is usually
short enough for interactive triage (`duo classify dev` with no flags) rather
than a batch artifact. `manifests` pins which registry manifests apply;
`policy` holds site-local classification overrides and always wins over a
manifest; `spec_version` must be an integer exactly equal to the engine's own,
and compilation refuses the repository outright if it is not. The layout the
agent expects around this file — `state/`, `media/`, and the optional `code/`
tree — is normative in
[spec/repo-format.md](../../spec/repo-format.md#layout).

`sandbox/setup.sh` is a live example of that fork, not a second copy of the
seed to trust literally: its committed `siterepo/a/site.duo.json` omits
`term_meta` — one key short of the seed above — which is exactly why no spike
suite that consumes it ever calls `duo init` on it; every one of them
`classify`s/`capture`s against it directly, as the deliberately manual,
operator-owned repository it already is. Match the seed byte-for-byte,
`term_meta` included, if you want `duo init` to keep owning the file instead.

Repositories initialized by `duo init` already include the required ignore
rules and initial baseline; continue with pending review and the daily
workflow rather than recapturing merely to manufacture a first snapshot.

That `.gitignore` — already copied above, before either path's first write —
covers init's sealed recovery journal (`.duo-init-attempt` and its
`.duo-init-attempt.next` transition), its unpublished `.duo-init-code-*`
staging root, capture's publication artifacts
(`state.capture.lock`, `state.capture-staging/`, `state.capture-backup/`,
`state.capture-intent`, `state.capture-receipt`, their fixed `.previous`/`.next`
transition slots, and `.tmp*`), and the optional
per-environment `.duo-env-values.json` scratch file. Omitting
`state.capture.lock` in
particular is not cosmetic: the template's own comment records the structural
failure it causes the moment two environments capture on both sides of a pair
before the second one pulls.

Init's hidden `.*.duo-init-*` temporary and claim names are ignored for Git
hygiene but remain ownership evidence. If one survives a crash, keep the
repository quiesced and treat the init plan as non-confirmable until the
documented archive-and-recreate recovery has been completed.

Those `state.capture*` names are Duo-owned protocol boundaries, not editable
repository content. During capture or recovery, keep shell automation and all
other non-Duo writers out of that namespace; the capture lock serializes Duo
processes but cannot fence an unrelated filesystem writer.

If an interrupted init reports that a partial code, media, state-staging, or
Git payload has no completed deletion manifest, it deliberately retains the
sealed journal and lock instead of guessing that the partial bytes are safe to
delete. Keep the repository quiesced and preserve that complete root for
inspection. The conservative retry is to archive the entire interrupted site
repository, recreate the configured path as a new ordinary empty directory
(restoring only reviewed adoption-seed files), and run `duo init` again; never
delete only the journal and leave its partial payload behind.

The generated template already keeps the root-local machine registry
(`/.duo-envs.json`), operational artifact/checkpoint directory (`/.duo/`), and
environment-value scratch file (`/.duo-env-values.json`) out of Git. The root
anchors preserve legitimate same-named files inside vendored code. When the
split locked components, a second labelled block holds their root-anchored
`/code/wp-content/<root>/<component>/` lines; those are the exact lines the
compile gate reads back, and the repository-root `.gitignore` is the only place
they may live — one inside `code/wp-content/` refuses compile, and one inside
`code/wp-content/plugins/` would be shipped to the target as payload. `duo doctor`
separately checks that the environment-value file is not Git-tracked — a
tracked one is a blocking failure wherever it can find a `git` binary to check
with.

## Prove a second environment before you promote anything

One captured environment proves capture. It does not prove the repo is
portable, which is the property the whole system rests on. Install matching
plugin and theme code on an independent target, adopt it, transfer only the
site repo, and converge it:

```sh
cli/duo plan target --adopt-by-slug=posts,terms,menus --default-author=admin
cli/duo apply target --adopt-by-slug=posts,terms,menus --default-author=admin
cli/duo capture target
```

The acceptance result is four facts together, not any one of them: apply's
convergence canary passes; the source and target canonical `state/` trees are
byte-identical after recapture; the source runtime checksums did not change
during capture; and the target runtime checksums did not change during apply.
A clean final plan is the receipt.

`--adopt-by-slug` exists for independently created WordPress defaults that
happen to share a natural key. Inspect every collision before granting it, and
narrow the listed entity kinds as far as you can — it is an authorization, not
a convenience flag.

## Where to go next

- [assess.md](assess.md) — reading the assessment properly, and turning it into
  a reviewed application contract.
- [daily-workflow.md](daily-workflow.md) — the loop your team runs after this.
- [release.md](release.md) — rehearse, authorize, release, verify: the loop the
  contract unlocks.
- [capabilities-and-limits.md](capabilities-and-limits.md) — what Duo manages,
  what it refuses, and how to read a red plan.
- [docs/adoption.md](../adoption.md) — the full adoption contract, including
  key handling, the verified-rollback profile, and the reproducible two-host
  certification transcript.
