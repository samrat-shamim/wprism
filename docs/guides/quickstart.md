# Quickstart: getting a site under Duo

`duo init <env>` is the shipped first-run path once the Duo agent is reachable
on an existing WordPress target. How the agent gets there is transport-specific:

1. **SSH:** `duo adopt` installs the agent, manifest library, and recovery
   runtime first; then `duo init` discovers and captures the site.
2. **Local or Docker:** the agent must already be installed or mounted; then
   `duo init` uses that authenticated transport directly. Automatic local and
   Docker agent delivery is a separate capability (DUO-3365), not something
   init silently performs.

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
SSH adoption creates it, while local/Docker control-plane setup or the site's
bind mount must create it before init. Init binds that exact directory before
reading or writing repository children and refuses if its identity changes.
The SSH account must be able to
write WordPress's actual `WPMU_PLUGIN_DIR` (discovered through the target's own
`wp eval`, never guessed from `wp_path`) and the environment's configured
`repo_path`.

The full prerequisite and safety contract is
[docs/adoption.md](../adoption.md); this guide is the narrative around it.

## Path A — adopt an existing site over SSH

SSH is the only transport `duo adopt` supports. This is not an arbitrary
restriction: adoption's job is to push a specific release's `agent/`,
`manifests/`, and recovery trees onto a machine you do not otherwise control,
and that shape only exists for the SSH transport today.

### 1. Describe the environment

Duo reads two optional registry files, found by walking upward from your
current directory: the committed `site.duo.json` and a gitignored, machine-
local `.duo-envs.json` overlay. The overlay replaces a same-named entry
*whole* — there is no per-key merge — so for any environment exactly one file
is the source of truth. Anything secret or machine-specific belongs in the
overlay.

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

Re-running `duo adopt` is the update mechanism. It replaces the agent and
manifest trees with the ones beside the invoking CLI and leaves the site's
policy untouched.

### 3. Review and confirm the first baseline

```sh
cli/duo init production
```

Init reads WordPress, PHP, database, active plugin/theme, certified adapter,
standard code-root, media, and value-redacted risk facts through the target
agent. The proposal is read-only and content-addressed. Confirmation sends its
digest—not a host-authored config—and refuses if any discovered fact changes.
Use `--yes` only for automation that has already preserved the rendered
proposal.

Before confirming, quiesce every non-Duo writer to the repository namespace,
including package managers, self-updaters, Git/shell automation, and processes
that can write `.git`, `code/`, `media/`, `state/`, or `state.capture*`. Init's
database lease and capture lock serialize Duo processes; they do not turn the
v0 PHP filesystem layer into an adversarial race sandbox. Keep that exclusion
in place until init succeeds or any retained recovery evidence is resolved.

On success the target repository is Git-ready and contains independent
`site.duo.json`, `code/wp-content`, `state`, and content-addressed `media`
contracts. Init finishes through ordinary `duo status`. Its clean statement is
limited to the selected managed adapters; unsupported site state is never
silently promoted into that claim, and the initial state capture is not a
promotion rollback checkpoint.

### 4. Record what must not move

Before capture, record checksums for state that has to stay local to this
runtime: plugin tokens, caches, derived indexes, custom-table rows. The exact
query is plugin-specific — keep both the command and its output with the change
record. You will re-run it after capture, and the comparison is the actual
acceptance test.

### 5. Measure, then review

```sh
cli/duo coverage production --format=json > production-coverage.json
cli/duo pending production
cli/duo classify production --export-batch=production-review.json
```

`coverage` is a survey, not a green gate: it never blocks anything and never
appears in the review queue. Read it against the inventory you expect. A plugin
with uncovered state needs a manifest or table schema — it cannot be made safe
by classifying an unrelated option.

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
  "allow_secret": false
}
```

Fill every `decisions[].class`. The vocabulary is explained in
[capabilities-and-limits.md](capabilities-and-limits.md#the-five-classes); the
short form is `authored` only for portable intent, `runtime` for
environment-local operational state, `derived` for state a declared
regeneration path rebuilds, `env` for separately provisioned per-environment
values, and `managed` for lifecycle-managed options.

### 6. Apply the batch — then look again

```sh
cli/duo classify production --apply-batch=production-review.json
cli/duo pending production
```

The artifact is bound to the environment and to the exact pending evidence by
SHA-256. If a write landed after export, apply refuses *before* touching
`site.duo.json` — export and review a fresh batch. Partial batches refuse for
the same reason.

**The capture gate is an empty queue, not a successful batch.** One layer of
decisions routinely exposes another, so keep looping until `duo pending` prints
`review queue is empty`.

### 7. Capture, then check the checksums again

```sh
cli/duo capture production
```

Then re-run the runtime checksums from step 4. **Any changed runtime checksum
is a failed adoption, even when capture exits 0.** Capture is supposed to
observe the site, not to perturb it; a moved checksum means something in the
classification is wrong, and a green exit code does not overrule that.

Keep the source repo as the canonical artifact. Do not copy the WordPress
database to another host to "prove" portability — that proves the database
copied, which was never in question.

## Path B — a local or Docker site

`duo adopt` remains SSH-only. Install or mount the Duo agent and manifest
library through the local environment's own control-plane setup, declare the
environment, and run the same public initializer:

```sh
cli/duo init dev
```

The target needs WordPress, WP-CLI, Git, a standard supported `wp-content`
layout, and a writable, pre-existing ordinary `repo_path` with no symbolic-link
ancestor. Init may create the Git worktree and Duo contracts inside that empty
or adoption-seed directory, but it does not create the directory, install
WordPress, or deliver the agent. It refuses before confirmation when those
prerequisites or the certified managed boundary are not present.

Hand-author `site.duo.json` only when you intentionally need a policy that the
discovered initializer cannot propose. The minimal manual shape remains the
one the sandbox uses (`sandbox/setup.sh`):

The minimal working shape is the one the sandbox uses (`sandbox/setup.sh`):

```json
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
```

That is the whole file. `manifests` pins which registry manifests apply;
`policy` holds site-local classification overrides and always wins over a
manifest; `spec_version` must be an integer exactly equal to the engine's own,
and compilation refuses the repository outright if it is not. The layout the
agent expects around this file — `state/`, `media/`, and the optional `code/`
tree — is normative in
[spec/repo-format.md](../../spec/repo-format.md#layout).

Add the environment to `.duo-envs.json` (or to this file's own `envs` key, if
every teammate shares it):

```json
{
  "envs": {
    "dev": {"transport": "local", "wp_path": "/var/www/html", "repo_path": "/home/me/site"},
    "e1":  {"transport": "docker", "compose_file": "sandbox/docker-compose.yml",
            "service": "cli-e1", "repo_path": "/siterepo"}
  }
}
```

For a deliberately manual repository, run `duo doctor`, `duo pending`,
`duo classify`, and `duo capture`. A fresh site's first queue is usually short
enough for interactive triage (`duo classify dev` with no flags) rather than a
batch artifact. Repositories initialized by `duo init` already include the
required ignore rules and initial baseline; continue with pending review and the daily
workflow rather than recapturing merely to manufacture a first snapshot.

Both paths need the same `.gitignore`, and hand-rolling it is a mistake people
make once. Copy the canonical template — exactly what `sandbox/setup.sh` does
one line after writing `site.duo.json`:

```sh
cp sandbox/site-repo.gitignore.template <repo>/.gitignore
```

That covers init's sealed recovery journal (`.duo-init-attempt` and its
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

Then append the two orchestrator-side lines, which the template does not carry:

```gitignore
.duo-envs.json
.duo/
```

The first keeps the machine-local registry overlay out of git; the second keeps
the operational artifact and checkpoint directory out. `duo doctor` separately
checks that `.duo-env-values.json` is not git-tracked — a tracked one is a
blocking failure wherever it can find a `git` binary to check with, which is
the template's line earning its place.

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

- [daily-workflow.md](daily-workflow.md) — the loop your team runs after this.
- [capabilities-and-limits.md](capabilities-and-limits.md) — what Duo manages,
  what it refuses, and how to read a red plan.
- [docs/adoption.md](../adoption.md) — the full adoption contract, including
  key handling, the verified-rollback profile, and the reproducible two-host
  certification transcript.
