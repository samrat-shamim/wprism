# Quickstart: getting a site under Duo

There are two ways a WordPress site arrives at Duo, and they are genuinely
different procedures rather than two flavors of one wizard:

1. **An existing site you already run**, reachable over SSH. `duo adopt`
   installs the agent and manifest library onto it and seeds a site repo.
2. **A fresh or local site** — a new docker sandbox, a laptop install, an
   empty repo you intend to grow. Here you write `site.duo.json` by hand.

Both paths converge on the same first-capture runbook, and neither is a
one-command bootstrap. A golden path that takes an empty directory to a
captured site in one step — `duo init` — is **Planned (DUO-3336)** — not yet
shipped. Nothing below is a preview of it; everything below exists today.

## Before you start

The machine that runs `duo` needs PHP 8+ with Sodium, plus `ssh`, `scp`, and
`tar` if any environment uses the SSH transport. `duo` itself is dependency-
free: no composer, no vendored packages, and no WordPress on the orchestrator
host.

An SSH target needs PHP 8+ with Sodium and a working `fsync()`, a working `wp`
command, `tar`, and an installed WordPress. It does **not** need Git — the
agent never shells out to git, and adoption ships files, not a clone. The SSH
account must be able to write WordPress's actual `WPMU_PLUGIN_DIR` (discovered
through the target's own `wp eval`, never guessed from `wp_path`) and the
environment's configured `repo_path`.

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

### 3. Record what must not move

Before capture, record checksums for state that has to stay local to this
runtime: plugin tokens, caches, derived indexes, custom-table rows. The exact
query is plugin-specific — keep both the command and its output with the change
record. You will re-run it after capture, and the comparison is the actual
acceptance test.

### 4. Measure, then review

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

### 5. Apply the batch — then look again

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

### 6. Capture, then check the checksums again

```sh
cli/duo capture production
```

Then re-run the runtime checksums from step 3. **Any changed runtime checksum
is a failed adoption, even when capture exits 0.** Capture is supposed to
observe the site, not to perturb it; a moved checksum means something in the
classification is wrong, and a green exit code does not overrule that.

Keep the source repo as the canonical artifact. Do not copy the WordPress
database to another host to "prove" portability — that proves the database
copied, which was never in question.

## Path B — a fresh or local site

There is no bootstrap command here. `duo adopt` is SSH-only, so for a local or
containerized site you create the site repo yourself: an ordinary git
repository containing a hand-written `site.duo.json`.

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

Then run the same sequence Path A ends with — `duo doctor`, `duo pending`,
`duo classify`, `duo capture`. A fresh site's first queue is usually short
enough for interactive triage (`duo classify dev` with no flags) rather than a
batch artifact.

Both paths need these two lines in the site repo's `.gitignore`:

```gitignore
.duo-envs.json
.duo/
```

The first keeps the machine-local overlay out of git; the second keeps the
operational artifact/checkpoint directory out. `duo doctor` additionally checks
that `.duo-env-values.json` is not git-tracked, and treats a tracked one as a
blocking failure wherever it can find a `git` binary to check with.

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
