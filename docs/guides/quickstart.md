# Quickstart: getting a site under WPrism

There are two good first contacts: try WPrism on a disposable pair, or connect an
existing site. Neither asks you to invent `site.wprism.json` or an environment
registry by hand.

## Try WPrism without connecting a site

From a source checkout with PHP 8+, Docker with Compose, Git, and `jq`
available:

```sh
cli/wprism demo start
```

The command verifies and starts the exact WordPress 7.1 image pinned by digest,
creates two disposable sites and an ordinary Git repository, and publishes the
URLs and `admin / admin` login only after its managed core capability set
qualifies and its bounded whole-site release assessment exits ready. Any
unexpected live option, pending classification, undeclared table, or exit-3
assessment blocks setup rather than being relabeled as a usable demo.
Both HTTP ports bind to `127.0.0.1` only; the disposable weak credentials are
never published on every host interface.
It also creates one target-only comment; that is the runtime fact the later
apply must preserve.

Setup stops at `review_required`. Inspect the generated proposal, then make the
explicit page-only decision the command prints:

```sh
cli/wprism demo review --accept-page-only
```

Without that exact flag no proposal or repository byte changes. The review
keeps only `post_type:page` with capture/merge/release/verify authority, removes
the unused code-lifecycle effect instead of pretending an operator reviewed it,
accepts through the real contract command, and commits only `contract.json` and
`projection.json` before fast-forwarding the target.

Now edit **WPrism Demo Page** on the source site and follow the printed loop:

```sh
cli/wprism demo capture
git -C sandbox/siterepo/wprismdemo1 diff
cli/wprism demo apply
cli/wprism demo refusal
cli/wprism demo stop
```

`capture` uses the real orchestrator and leaves the result as an ordinary Git
diff. `apply` permits exactly that one page artifact, commits and transfers the
revision through Git, then runs the real `release demo-target --plan-only`
against the accepted contract. The preview must be nonempty, page-scoped,
revision/digest-bound, free of code/deletes/unknown effects, and byte-read-only
across both repositories and the target runtime. Only then does the demo run a
lower-level evaluation apply, prove the target page equals the captured
artifact, and require the target-only comment to remain byte-identical.
Production execution uses `stage-source` → `release prepare` → signed
authorization → `release execute`; the raw demo apply does not grant that
authority. `refusal` tries
to replace the trusted environment's repository binding with a caller-supplied
path and succeeds only when the host refuses before target contact, without
changing that runtime proof. `stop` removes the pair and its three
disposable repositories, so copy anything you want to keep first.

For the advanced adapter journey, `wprism demo start --scenario=woocommerce`
installs the exact digest-pinned WooCommerce 11.0.1 artifact, manages a product,
and proves that a target-only order and live stock survive the catalog change.

## Connect an existing site

Start by asking whether the native target is reachable without installing
anything. Keep an absolute path to the CLI because the next command moves into
the newly created site workspace:

```sh
WPRISM_CLI="$PWD/cli/wprism"
"$WPRISM_CLI" connect production --workspace=../my-site \
  --transport=ssh --host=deploy@wp.example.com \
  --wp-path=/var/www/html --repo-path=/home/deploy/site-repo
```

For a standard local Compose installation with a running `wordpress` service
and no WP-CLI service, select the managed controller tooling explicitly:

```sh
WPRISM_CLI="$PWD/cli/wprism"
"$WPRISM_CLI" connect local --workspace=../my-site \
  --transport=docker --compose-file=./compose.yml \
  --wordpress-service=wordpress --tooling=managed
```

The helper image is built from WPrism's digest-pinned recipe with fixed Git and
Git LFS packages. Its private Compose overlay inherits the application's
environment, networks, and WordPress volume without copying their resolved
values into the registry. It adds only a WPrism-owned repository volume and
never edits `compose.yml`. The web service must already be running; helper
commands use `run --no-deps`, so WPrism neither starts nor stops the app. An
existing WP-CLI/Git-capable service can be selected instead with `--service`,
`--wp-path`, and `--repo-path` alongside `--wordpress-service`.

The managed path currently supports a service based directly on the official
WordPress image with writable persistent `/var/www/html`. It revalidates the
running container, Compose project, local Docker context, and effective
`wp-content/mu-plugins` storage. Custom-built images and mounts that shadow the
MU control path refuse; unrelated plugin, theme, and upload mounts remain valid.
This is initial bootstrap only, not a claim that Docker release or verified
rollback is configured.

`connect` performs exactly three native inspection checks: transport
reachability, `wp core is-installed`, and single-site topology. Only after all
three pass does it create the dedicated Git root, the same minimal seed
adoption uses, the complete `.gitignore` boundary, and a mode-`0600`, untracked
`.wprism-envs.json`. A failed probe creates no workspace. WPrism issues no explicit
target mutation, but the topology check uses `wp eval`: it boots WordPress, so
site startup code may run and may have its own effects.

Now enter the workspace and run the composed flow. For the Docker `local`
connection above, explicitly authorize the narrowly scoped database setup and
name its running Compose database service:

```sh
cd ../my-site
"$WPRISM_CLI" onboard local --configure-database --database-service=db \
  --git-url=git@github.com:you/my-site.git
```

For the SSH `production` connection, no Docker database setup option applies:

```sh
cd ../my-site
"$WPRISM_CLI" onboard production --git-url=git@github.com:you/my-site.git
```

`onboard` fixes the order for a new site:

**adopt (deliver the agent) → assess (decide) → init (commit to a baseline).**

Each existing gate remains visible and fail-closed. The assessment is printed
before init asks for confirmation. `--git-url` must name an empty repository
that both the controller and WordPress target can reach with their own Git
credentials and SSH known-host configuration. WPrism verifies both paths and the
empty remote before adopt/init changes the target. It then commits and pushes
the initialized target baseline on the target's current branch and checks out
that exact branch into the connected workspace while the untracked local
registry stays in place. Without `--git-url`, onboarding stops after a
successful init and prints a resumable command. For the Docker example it is:

```sh
"$WPRISM_CLI" onboard local --handoff-only --git-url=<empty-remote-url>
```

That continuation performs only the repository handoff; it does not repeat
adopt, assess, or init.

The checkout makes the initialized revision reviewable locally; it does not
redirect the live environment. `wprism capture local` in the Docker path (or
`wprism capture production` in the SSH path) still writes only to that
environment's configured target `repo_path`, but now binds that write to the
caller's current named branch and refuses unless the target worktree is on the
same branch. From a detached or non-Git controller context, name the destination
explicitly with `--target-branch=<name>`; this verifies the target branch and
never switches it. Inspect with `"$WPRISM_CLI" assess local` for Docker or
`"$WPRISM_CLI" assess production` for SSH next. Before
capturing feature work, point or materialize the target environment to that
feature branch as described in [daily-workflow.md](daily-workflow.md); preview
materialization additionally requires the two provider-backed entries shown in
[release.md](release.md#create-a-preview).

On a Docker or local target whose control plane already carries the agent,
`wprism assess` is the very first WPrism command you run. [assess.md](assess.md) is
the guide to reading its output; this page is how you get there and what
happens after.

`wprism init <env>` is the shipped first-run path once the WPrism agent is reachable
on an existing WordPress target. How the agent gets there is transport-specific:

1. **SSH:** `wprism adopt` installs the agent with its embedded adapter library,
   plus the recovery runtime; then `wprism init` discovers and captures the site.
2. **Machine-local:** an untracked, explicit bootstrap opt-in lets `wprism adopt`
   prove and initially install that same control plane before `wprism init`. It
   refuses when a WPrism control plane is already present; installed-target
   updates use the existing environment update path.
3. **Local Docker Compose:** `wprism connect` can authorize a managed tooling
   service, or an existing WP-CLI/Git-capable service, after proving that a
   running WordPress service and the tooling share the same durable WordPress
   storage and that the repository is durable too. `wprism onboard` then uses
   the same transactional adoption path. It never edits the application Compose
   file or starts/stops the application services.

If the first baseline proposal reports that the database mutation boundary
lacks direct global `PROCESS`, a standard local Docker site can opt in with
`wprism init <env> --configure-database --database-service=<compose-service>`
(or pass the same flags to its first `wprism onboard`). WPrism discloses that
`PROCESS` is server-wide, proves the selected running official database service
is the server WordPress actually uses, and grants only that privilege to the
exact WordPress account. Ordinary `--yes` does not enable setup. MariaDB setup
requires immutable `server_uid` identity (11.1.6, 11.2.5, 11.4.3, 11.5.2, or
11.6.1+); older admitted MariaDB releases can still use ordinary read-only
onboarding, but this privilege-provisioning opt-in refuses them explicitly.

Init is not a WordPress installer. It starts from a working site and a reachable
agent, proposes the exact managed boundary without mutation, requires explicit
confirmation, and then creates separate code and canonical state/media
baselines. Unsupported extensions, custom code layouts, stale repository
payloads, or non-current certification evidence keep the proposal red.

## Before you start

The machine that runs `wprism` needs PHP 8+ with Sodium and `tar` for adoption,
plus `ssh` and `scp` if any environment uses the SSH transport. `wprism` itself is dependency-
free: no composer, no vendored packages, and no WordPress on the orchestrator
host.

An SSH target needs PHP 8+ with Sodium and a working `fsync()`, a working `wp`
command, `tar`, and an installed WordPress. Adoption itself ships files rather
than cloning a repository, but the subsequent `wprism init` path also requires a
working `git` binary on the target: init verifies or creates the target-owned
Git worktree before publishing its baseline.
When the site has attachment bytes, that target also needs Git LFS: the
read-only proposal proves `git lfs version`, and confirmation installs the
filter in the repository-local Git config before any media baseline is
published. Every developer machine that clones the repository needs Git LFS as
well; `media/**` is deliberately never handed to ordinary Git object storage.
The configured `repo_path` must be absent with an ordinary writable parent, or
be an explicitly authorized empty Docker mount root. Authorized SSH, local, and
Docker adoption creates the absent repository path; managed Docker tooling owns
and mounts its durable parent volume. Init binds that exact directory before
reading or writing repository children and refuses if its identity changes.
The SSH account must be able to
write WordPress's verified control-plane directory and the environment's
configured `repo_path`. Initial SSH/machine-local adoption discovers the standard
`wp-content/mu-plugins` layout through an isolated WordPress bootstrap; custom
content/MU roots and `SUNRISE` are unsupported. An absent repository is admitted
only after proving there is no prior WPrism control or recovery authority, not
as an exemption from the managed-site recovery fence.

The full prerequisite and safety contract is
[docs/adoption.md](../adoption.md); this guide is the narrative around it.

## The lower-level primitives

Use the individual steps below when a site repository and registry already
exist, or when you need to stop between gates. New source-checkout users should
prefer `connect` and `onboard` above.

### Adopt an existing site over SSH, local, or authorized local Docker

SSH always exposes the explicit transfer mechanism. Local and Docker environments
expose initial delivery only through an exact untracked machine-local opt-in.
Docker additionally proves a local unix/npipe daemon, one running web-service
container, exact shared persistent WordPress storage, and persistent repository
storage; neither shell access nor a bind mount alone grants bootstrap authority.

#### 1. Describe the environment

WPrism searches upward for the committed `site.wprism.json`, but inside Git accepts
it only at the current worktree root, then reads a gitignored, machine-local
`.wprism-envs.json` overlay beside it. Before a site file exists, the automatically
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

For a local target, put the full entry in the untracked `.wprism-envs.json`
overlay (not `site.wprism.json`):

```json
{
  "envs": {
    "dev": {
      "transport": "local",
      "wp_path": "/srv/wordpress",
      "repo_path": "/srv/site-repo",
      "bootstrap": {"format": "wprism-local-control-plane/v1"}
    }
  }
}
```

`wprism driver-capabilities dev --operation=adopt` checks only that closed local
authorization and never contacts the target. `wprism adopt dev` then isolates
ordinary plugin/theme/MU loading, proves WordPress and a safe disjoint
filesystem topology read-only, and refuses with remediation before creating
an archive or target path when that proof is red.

#### 2. Check, then adopt

Run these from the connected site workspace with `WPRISM_CLI` pointing to the WPrism
source checkout whose `cli/`, `agent/`, `adapter-packages/`,
`platform/adapter-library/`, and `recovery/` directories are the release you
intend to install. Adoption assembles the package and platform sources into
`agent/adapter-library/`, then ships exactly `agent recovery`, so the checkout
you invoke is the version the target gets.

```sh
"$WPRISM_CLI" doctor production   # expected to report the agent/repo missing first
"$WPRISM_CLI" adopt production
```

The first command is supposed to fail. Running it anyway is worth the ten
seconds: it tells you whether the transport, the WordPress install, and the
paths are right *before* adoption starts moving files, and it gives you a
before/after pair for the same nine checks adoption ends with.

`wprism adopt` verifies reachability, discovers `WPMU_PLUGIN_DIR`, installs the
agent, loader, embedded adapter library, and recovery runtime, creates the protected
control root and stable target identity, creates `repo_path/site.wprism.json`
**only when it is absent**, then proves in fresh wp-cli processes that the
installed agent version matches this checkout exactly and that the policy
loads. It ends by running the same `wprism doctor` checks, and succeeds only when
every blocking one passes.

Over SSH, re-running `wprism adopt` is the update mechanism: it replaces the
installed agent (including its embedded adapter library) and recovery runtime
from the invoking checkout's freshly assembled `agent recovery` archive, and
leaves the site's policy untouched. The privileged machine-local bootstrap is
initial-only and refuses a second adoption rather than racing an installed
recovery authority.

#### 3. Assess before you initialize

```sh
"$WPRISM_CLI" assess production
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

Assess also writes `.wprism/contract/<env>/proposed.json` in your local site
repository — per environment, because the proposal describes one environment,
while the contract it becomes is one per site. That is the beginning of the
reviewed application contract that `wprism release`, `wprism verify` and `wprism recover`
all read later; you do not have to deal with it now, and
[assess.md](assess.md#record-the-decision-the-application-contract) picks it up
when you do.

If the assessment says the site is not a fit, you have learned that before
creating a baseline, which is the whole reason this step is here rather than
after step 7.

#### 4. Review and confirm the first baseline

```sh
"$WPRISM_CLI" init production
```

Init reads WordPress, PHP, database, active plugin/theme, certified adapter,
standard code-root, media, and value-redacted risk facts through the target
agent. The proposal is read-only and content-addressed. Confirmation sends its
digest—not a host-authored config—and refuses if any discovered fact changes.
Use `--yes` only for automation that has already preserved the rendered
proposal.

`wprism init` also **classifies the code half**, and Git never carries third-party
code: each active plugin and theme either LOCKS — against its published wp.org
component release when the installed bytes hash-match it, against the exact
theme tree nested in the target WordPress version's verified core release, or
against an archive you imported on this host with `wprism code-import
<archive.zip>` (a premium plugin, a vendor theme) — and is declared in
`code/wprism-code.lock.json` and kept out of Git, or is declared the site's own
code with `--first-party=<root>/<slug>` and
carried in Git by that declaration. A component that is neither blocks the
proposal with both remedies named; there is no "vendor it anyway".
Classification runs on the orchestrator host, never on the target, and the
proposal prints every component with its classification and the reason for it
before you confirm — the classification is inside the digest, so a stale
`--confirm` cannot apply one you did not read. `--offline` contacts no
registry: wp.org components lock only from the host cache. See
[code-updates.md](code-updates.md#the-code-half-git-never-carries-third-party-code).

Before confirming, quiesce every non-WPrism writer to the repository namespace,
including package managers, self-updaters, Git/shell automation, and processes
that can write `.git`, `code/`, `media/`, `state/`, or `state.capture*`. Init's
database lease and capture lock serialize WPrism processes; they do not turn the
v0 PHP filesystem layer into an adversarial race sandbox. Keep that exclusion
in place until init succeeds or any retained recovery evidence is resolved.

On success the target repository is Git-ready and contains independent
`site.wprism.json`, `code/wp-content`, `state`, and content-addressed `media`
contracts. Init publishes `.gitattributes` with the closed
`media/** filter=lfs diff=lfs merge=lfs -text` rule and verifies the effective
attribute before capture — plus `code/wprism-code.lock.json` when the split locked at least one
component. The locked components' bytes are on disk and compile normally at the
target; they are simply not in Git, so a fresh clone needs the materialization
step in [code-updates.md](code-updates.md#the-materialization-step) before its
first compile. WPrism refuses that compile by name (`code_component_unresolved`)
rather than producing a shrunken payload. Init finishes through ordinary `wprism status`. Its clean statement is
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
"$WPRISM_CLI" coverage production --format=json > production-coverage.json
"$WPRISM_CLI" pending production
"$WPRISM_CLI" classify production --export-batch=production-review.json
```

`coverage` is a survey, not a green gate: it never blocks anything and never
appears in the review queue. Read it against the inventory you expect. A plugin
with uncovered state needs a manifest or table schema — it cannot be made safe
by classifying an unrelated option.

Its four option buckets sum to `total`. A **declared** name is never invisible,
whatever its class: an option an adapter declares `env`, `runtime` or `derived`
is *declared and excluded*, counted under `declared-excluded` (broken out per
class in `--format=json`), and carries no action — the adapter models it and
says WPrism must not version it. `invisible` means the opposite: **no rule from any
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
  "allow_pii": false,
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

If pending marks the row `[SECRET: ...]` or `[PII: ...]`, authoring it requires
setting the matching `allow_secret` or `allow_pii` field to `true` after exact
review. The batch carries only redacted categories; it never exports the live
value. That approval remains attached to the exact row on the target command;
it cannot clear another row that becomes sensitive during the host-to-target
handoff.

An **options** row needs one more answer, and only the one its class reads:
`autoload` when the class is `authored` or `managed` (`preserve` replays the
source row's own flag; `yes|no|auto|on|off|auto-on|auto-off` pins it), and the
boolean `required` when the class is `env` (`true` if an operator must
provision the value on a fresh environment). Both are `null` on export and
neither is ever guessed — the same posture as `pending`'s proposals.

### 7. Apply the batch — then look again

```sh
"$WPRISM_CLI" classify production --apply-batch=production-review.json
"$WPRISM_CLI" pending production
```

The artifact is bound to the environment and to the exact pending evidence by
SHA-256. If a write landed after export, apply refuses *before* touching
`site.wprism.json` — export and review a fresh batch. Partial batches refuse for
the same reason, and so does a row whose class needs `autoload` or `required`
and does not have it: the refusal names the row and the field, and no remote
write is opened.

**The capture gate is an empty queue, not a successful batch.** One layer of
decisions routinely exposes another, so keep looping until `wprism pending` prints
`review queue is empty`.

### 8. Capture, then check the checksums again

```sh
"$WPRISM_CLI" capture production
```

Then re-run the runtime checksums from step 5. **Any changed runtime checksum
is a failed adoption, even when capture exits 0.** Capture is supposed to
observe the site, not to perturb it; a moved checksum means something in the
classification is wrong, and a green exit code does not overrule that.

Keep the source repo as the canonical artifact. Do not copy the WordPress
database to another host to "prove" portability — that proves the database
copied, which was never in question.

## Path B — a site whose transport has no bootstrap authority

Install or mount the WPrism agent with its embedded adapter library through that
environment's own control-plane setup — this path has no `wprism adopt` step, so nothing else
will deliver it. "The agent is already there, so the assessment costs one
read-only run" is true, but `wprism assess` still needs a repository to run
*against*: it walks upward from the current directory for `site.wprism.json` and
refuses with `[local_site_repo_missing]` — "run this command from inside the
site repository that holds site.wprism.json" — the moment it finds none. Path A
gets that file as a side effect of `wprism adopt` (step 2 above, "creates
`repo_path/site.wprism.json` only when it is absent"); Path B has no such step,
so give assess a repository first, then assess, then init:

```sh
mkdir -p <repo>
cat > <repo>/site.wprism.json <<'EOF'
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
`InitPlanner` compares a repository's `site.wprism.json` against
(`agent/src/Init/InitPlanner.php:953-1060`, `is_adoption_seed()`/
`existing_config()`'s `$seed`): the one state in which `wprism assess` previews
init's own proposal instead of the seed's trivial `core`-only pin set, and the
one state in which `wprism init` still owns the file and will recompute and
republish it rather than refuse. The `.gitignore` goes on before anything else
touches the directory because `assess` itself — not only `init` — starts
writing scratch here: its `.wprism/contract/<env>/proposed.json` write (step 3 above)
needs `/.wprism/*` ignored from its very first run. The generated exception for
`.wprism/authority/authorities.json` is narrow: it admits the reviewed release
trust policy, not signing keys or runtime control state. `git -C <repo> init` makes
`<repo>` its own Git worktree root; skip that and, if `<repo>` was created
inside some other project's checkout — the easy mistake on a first
experiment — its `site.wprism.json` is refused as a nested registry rather than
trusted, by both `assess` and `init`.

Add the environment next to it, in an untracked `.wprism-envs.json` — on this
path, only there. `site.wprism.json` can carry a shared `envs` key in general,
but the seed comparison is against the *whole decoded file* (the only
things `existing_config()` sets aside are source-bearing manifest pins and
the scope rules those pins wrote — the paragraph below), so an `envs` key
inside `site.wprism.json` makes the repository operator-owned and `wprism init`
refuses it as `existing_configuration`. Share
environments through the site file only on the deliberately-manual path
below, after deciding init is not for you:

```json
{
  "envs": {
    "dev": {"transport": "local", "wp_path": "/var/www/html", "repo_path": "/home/me/site"},
    "e1":  {"transport": "docker", "compose_file": "compose.yml",
            "service": "cli", "wordpress_service": "wordpress",
            "wp_path": "/var/www/html", "repo_path": "/siterepo"}
  }
}
```

Then assess before you initialize — nothing here writes to the target, and a
site full of blocked surfaces is still a successful assessment:

```sh
"$WPRISM_CLI" assess dev
"$WPRISM_CLI" init dev
```

The target itself needs WordPress, WP-CLI, Git, and a standard supported
`wp-content` layout; `repo_path` — a writable, pre-existing ordinary
directory with no symbolic-link ancestor — is the one piece of that WPrism will
not create for you, on any transport; you already did, in the step above. Init
may create the Git worktree pieces and WPrism contracts it owns inside that seed
directory, but it does not install WordPress or deliver the agent, and it
refuses before confirmation when those prerequisites or the certified managed
boundary are not present. An adoption seed that already carries explicit
`{name, source: "site"|"plugin", digest}` pins — what `wprism adapter certify
--pin` and `wprism adapter pin` write for an operator-authored or overriding
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

Hand-author `site.wprism.json` instead of taking init's proposal only when you
intentionally need something init cannot discover on its own. The seed above
is the exact fork line, not just a starting point: a repository whose
`site.wprism.json` is byte-identical to it is still init-owned no matter who
typed the bytes, `term_meta` included — `wprism init` accepts and republishes it
unchanged. Pin a real manifest, edit `policy`, or drop one key, and the
repository becomes operator-owned instead: `wprism init` refuses with
`existing_configuration` — "the repository already has a non-seed WPrism
configuration" — correctly, because by then it is one. Continue that
repository with `wprism doctor`, `wprism pending`, `wprism classify`, and
`wprism capture` instead of `wprism init`; a fresh site's first queue is usually
short enough for interactive triage (`wprism classify dev` with no flags) rather
than a batch artifact. `manifests` pins which registry manifests apply;
`policy` holds site-local classification overrides and always wins over a
manifest; `spec_version` must be an integer exactly equal to the engine's own,
and compilation refuses the repository outright if it is not. The layout the
agent expects around this file — `state/`, `media/`, and the optional `code/`
tree — is normative in
[spec/repo-format.md](../../spec/repo-format.md#layout).

`sandbox/setup.sh` is a live example of that fork, not a second copy of the
seed to trust literally: its committed `siterepo/a/site.wprism.json` omits
`term_meta` — one key short of the seed above — which is exactly why no spike
suite that consumes it ever calls `wprism init` on it; every one of them
`classify`s/`capture`s against it directly, as the deliberately manual,
operator-owned repository it already is. Match the seed byte-for-byte,
`term_meta` included, if you want `wprism init` to keep owning the file instead.

Repositories initialized by `wprism init` already include the required ignore
and Git LFS attribute rules plus the initial baseline; continue with pending review and the daily
workflow rather than recapturing merely to manufacture a first snapshot.

That `.gitignore` — already copied above, before either path's first write —
covers init's sealed recovery journal (`.wprism-init-attempt` and its
`.wprism-init-attempt.next` transition), its unpublished `.wprism-init-code-*`
staging root, capture's publication artifacts
(`state.capture.lock`, `state.capture-staging/`, `state.capture-backup/`,
`state.capture-intent`, `state.capture-receipt`, their fixed `.previous`/`.next`
transition slots, and `.tmp*`), and the target-local
per-environment `.wprism-env-values.json` intended-value file. Omitting
`state.capture.lock` in
particular is not cosmetic: the template's own comment records the structural
failure it causes the moment two environments capture on both sides of a pair
before the second one pulls.

Init's hidden `.*.wprism-init-*` temporary and claim names are ignored for Git
hygiene but remain ownership evidence. If one survives a crash, keep the
repository quiesced and treat the init plan as non-confirmable until the
documented archive-and-recreate recovery has been completed.

Those `state.capture*` names are WPrism-owned protocol boundaries, not editable
repository content. During capture or recovery, keep shell automation and all
other non-WPrism writers out of that namespace; the capture lock serializes WPrism
processes but cannot fence an unrelated filesystem writer.

If an interrupted init reports that a partial code, media, state-staging, or
Git payload has no completed deletion manifest, it deliberately retains the
sealed journal and lock instead of guessing that the partial bytes are safe to
delete. Keep the repository quiesced and preserve that complete root for
inspection. The conservative retry is to archive the entire interrupted site
repository, recreate the configured path as a new ordinary empty directory
(restoring only reviewed adoption-seed files), and run `wprism init` again; never
delete only the journal and leave its partial payload behind.

The generated template already keeps the root-local machine registry
(`/.wprism-envs.json`), operational artifact/checkpoint directory (`/.wprism/*`, with
only the reviewed `.wprism/authority/authorities.json` policy admitted), and
environment intended-value file (`/.wprism-env-values.json`) out of Git. The root
anchors preserve legitimate same-named files inside vendored code. When the
split locked components, a second labelled block holds their root-anchored
`/code/wp-content/<root>/<component>/` lines; those are the exact lines the
compile gate reads back, and the repository-root `.gitignore` is the only place
they may live — one inside `code/wp-content/` refuses compile, and one inside
`code/wp-content/plugins/` would be shipped to the target as payload. `wprism doctor`
separately checks that the environment-value file is not Git-tracked — a
tracked one is a blocking failure wherever it can find a `git` binary to check
with.

## Prove a second environment before you promote anything

One captured environment proves capture. It does not prove the repo is
portable, which is the property the whole system rests on. Install matching
plugin and theme code on an independent target, adopt it, transfer only the
site repo, and converge it:

```sh
"$WPRISM_CLI" plan target --adopt-by-slug=posts,terms,menus --default-author=admin
"$WPRISM_CLI" apply target --adopt-by-slug=posts,terms,menus --default-author=admin
"$WPRISM_CLI" capture target
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
- [capabilities-and-limits.md](capabilities-and-limits.md) — what WPrism manages,
  what it refuses, and how to read a red plan.
- [docs/adoption.md](../adoption.md) — the full adoption contract, including
  key handling, the verified-rollback profile, and the reproducible two-host
  certification transcript.
