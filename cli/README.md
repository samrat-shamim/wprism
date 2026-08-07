# duo — the orchestrator CLI

`duo` is the host-agnostic, multi-environment front end for the Duo agent.
Git stays git — branching, merging, and history all happen on the site repo
exactly as before. `duo` only adds two things on top of the per-environment
`wp duo capture|plan|apply` commands (see [agent/src/Cli.php](../agent/src/Cli.php)):

1. **Transports** — so you don't have to remember whether a given
   environment is reached over `ssh`, `docker compose run`, or a plain local
   `wp --path=`.
2. **Cross-environment ergonomics** — `duo envs`/`duo doctor`/`duo status`,
   which no single-environment `wp duo …` call can give you.
3. **Truthful code-and-state sequencing** — compilation produces one
   content-addressed artifact whose `artifact_hash` binds the state revision
   and, when present, its separate code revision. `duo deploy` materializes
   that code around the lifecycle window; `duo promote` additionally takes a
   database checkpoint and converges the bound state revision under one
   target-database promotion lease.

`duo` is dependency-free PHP 8+: no composer, no vendored packages, no
WordPress required on the machine that runs it. Requires only a `php`
binary and, per environment, whatever the transport itself needs (`ssh`,
`docker compose`, or a local `wp-cli`).

## Usage

```
duo envs
duo doctor <env>
duo adopt  <env>
duo status <env>
duo capture <env> [extra wp-cli flags...]
duo plan    <env> [extra wp-cli flags...]
duo apply   <env> [extra wp-cli flags...]
duo deploy  <env> [--force-code-mismatch] [--force-code-drift]
duo env-set <env> --name=<name> (--value=<value> | --stdin)
duo promote <env> [extra apply flags...]
duo pending <env>
duo classify <env> [--accept-proposals]
duo -h | --help
```

Run `duo --help` for the full usage text (verbs, global flags, registry shape).

- **`duo envs`** — lists every environment in the merged registry with a
  one-line transport summary. Exit 0 if the registry has at least one
  environment (even if some environments' configs are individually invalid —
  those print as an inline `ERROR: …` row instead of failing the whole
  listing), exit 1 if no `envs` were found anywhere.

- **`duo doctor <env>`** — gated checks, each skipped (reported as a
  failure) once an earlier one fails, since a broken transport makes every
  later check meaningless noise:
  1. transport reachable (`echo` round-trips through the transport)
  2. WordPress installed (`wp core is-installed`)
  3. the duo agent is present (`wp eval` checks `class_exists('\Duo\Capture')`)
  4. `repo_path` exists and contains `site.duo.json`
  5. `.duo-env-values.json` (DUO-3232's optional per-environment secrets
     scratch file — see "Env-bound value provisioning" below) is not
     git-tracked. **Advisory, not blocking, when this environment has no
     `git` binary to check with** — verified live that this project's own
     sandbox images (`wordpress:cli-php8.3`) genuinely don't ship one, so
     this degrades to "could not verify" (a WARN naming exactly that)
     rather than a silent, wrong PASS; when git *is* available, a tracked
     file is a real, blocking failure, same severity as checks 1-4.
  6. `DISALLOW_FILE_MODS` is set (advisory — DUO-3231, closes the wp-admin
     file-mod UI that can silently drift installed code out from under git)
  7. installed PHP version is inside `docs/compatibility-baseline.json`'s
     declared range (DUO-3222)
  8. installed database engine/version is inside the same baseline
  9. installed WordPress core version (informational only — reported, never
     enforced; see the baseline file's own note on why)

  Exit 0 only if every check above except the ones that were actually
  advisory/informational on this particular run (always 9; 6 whenever
  `DISALLOW_FILE_MODS` is genuinely unset; 5 only in the no-git case)
  passes.

- **`duo adopt <env>`** — installs or updates this checkout's complete Duo
  agent and manifest library on a pre-existing SSH target, creates a minimal
  core-only `site.duo.json` only when that file is absent, verifies
  the exact installed agent version and policy load in fresh wp-cli processes,
  then runs `duo doctor`. Existing site policy is retained. The target needs
  no Git and the install does not rely on `DUO_MANIFESTS_DIR` surviving into
  an SSH login. See the operator procedure and safety/update contract in
  [docs/adoption.md](../docs/adoption.md).

- **`duo status <env>`** — runs `wp duo plan --repo=<repo_path>
  --format=json` (note: `--format=json`, not `--json` — wp-cli's dispatcher
  rewrites a bare `--json` into `--format=json` before the command ever sees
  `$assoc['json']`; see the comments in `agent/src/Cli.php`) and renders a
  human summary: counts per plan bucket (create/update/adopt/unchanged/
  drift/conflict/collision/delete/code_mismatch/code_drift/incomplete_apply/
  regen_pending/env_missing), drift paths, blocked-delete reasons,
  code_mismatch and code_drift findings (the latter is an installed code
  version/provenance change after Duo's last trusted observation),
  regen_pending entries (a derived table with a hard per-entity
  availability dependency — DUO-3234, e.g. TEC's tec_occurrences — whose
  post-apply verification failed and hasn't yet resolved), env_missing
  entries (a manifest-declared `class: "env"` option unset on this
  environment — DUO-3232, see "Env-bound value provisioning" below), and
  any plan-level warnings.

  Exit non-zero ("not safe to promote") if the plan contains any
  `conflict`, `collision`, `code_mismatch`, or `code_drift` entry, any
  blocked delete, any ordinary state drift, a retained `incomplete_apply`
  marker, a `regen_pending` entry, or a **required** `env_missing` entry.
  `duo apply` refuses code_drift unless explicitly passed
  `--force-code-drift`; ordinary state drift and regen_pending are two
  different cases apply itself does *not* refuse on (a drifted entity just
  folds into `update` once the repo side changes too, or stays `drift`
  otherwise; a regen_pending marker is what makes the *next* apply retry,
  not something the apply that set it refuses on). Env_missing is a third:
  apply never refuses on it at all (env values are never captured/applied —
  there is nothing for apply's own preconditions to check), but status still
  reports a required-and-missing entry as not clean because it answers
  "safe to promote?", not just "will apply refuse?" — capture first for
  ordinary drift, retry for regen_pending (automatic on the next `duo
  apply`), `duo env-set` for env_missing. An *optional* (`required: false`)
  env_missing entry is still listed for visibility but never flips this by
  itself — it's plugin-internal bookkeeping the plugin populates on its own.
  Also non-zero if the underlying `wp duo plan` call itself failed or
  returned unparseable JSON. Plain warnings are rendered but never flip this
  by themselves — see the decision-matrix comment in
  `cli/src/PlanSummary.php::render()`.

  `code_revision_stale` is stricter than a lifecycle compatibility finding:
  the current artifact's code payload has not completed the host
  `duo deploy <env>` stage → lifecycle → finalize sequence. It is an
  unconditional code-before-state ordering gate. Neither
  `--force-code-mismatch` nor `--force-code-drift` permits `duo apply` to
  cross it.

  `duo status` parses and reformats; it does not print the raw JSON. Use
  `duo plan <env> --format=json` for that.

- **`duo capture|plan|apply <env> [flags...]`** — pure passthrough to `wp duo
  capture|plan|apply --repo=<repo_path> [flags...]` for that environment.
  Every flag after `<env>` is forwarded verbatim — e.g.:

  ```
  duo apply e2 --adopt-by-slug=terms --default-author=admin --with-deletes
  ```

  stdout/stderr stream live (not buffered/reformatted) and the exit code is
  exactly the agent's exit code.

- **`duo env-set <env> --name=<name> (--value=<value> | --stdin)`** — pure
  passthrough to `wp duo env-set --repo=<repo_path> --name=<name> …`, same
  live-streaming/exit-code contract as capture/plan/apply above. This is
  the one passthrough verb where that matters for more than consistency:
  `--stdin` reads the value from STDIN with the terminal's echo disabled,
  and passthrough's use of `passthru()` (rather than the captured-output
  `proc_open` doctor/status use) is exactly what lets STDIN reach the
  agent process interactively through any of the three transports. Named
  `--stdin`, not `--prompt` — wp-cli reserves `--prompt` globally for its
  own generic per-parameter prompting and consumes it before any command
  ever sees it, confirmed live rather than assumed. See "Env-bound value
  provisioning" below for what this command is for.

- **`duo deploy <env> [--force-code-mismatch] [--force-code-drift]`** — the
  standalone lifecycle/code path. It compiles once into the target's
  `.duo/artifacts/` directory, begins an exact target owner/artifact session,
  and deliberately takes no database checkpoint.
  The artifact has a state revision and may have a separate, opaque code
  descriptor/revision; its `artifact_hash` binds both. When that descriptor is
  present, the host carries the one frozen artifact, its expected outer hash,
  and generated owner through
  `wp duo code-stage` → `wp duo deploy --materializing-code` →
  `wp duo code-finalize`. It does not interpret descriptor fields or mutate
  files itself — those checks and mutations belong to the target agent. An
  artifact without a code descriptor retains the established lifecycle-only
  deploy path. Stop-on-first-failure and the target command's exit code apply
  to every phase. A `code_revision_stale` finding is recovered only by this
  host workflow; force flags may override explicit lifecycle compatibility or
  drift findings, never the verified code-before-state ordering witness.
  The target database lease serializes Duo writers only. Operators must exclude
  package managers, self-updaters, and other direct `WP_CONTENT_DIR` writers
  during stage/finalize; stable symlinks are refused, but this v0 PHP
  materializer is not an adversarial filesystem-race sandbox.

- **`duo promote <env> [apply flags...]`** — the normal fail-closed
  code-and-state promotion path. It compiles the repository once into
  `.duo/artifacts/`, acquires a target-DB lease bound to that artifact's outer
  `artifact_hash`, then exports the target database into `.duo/checkpoints/`.
  The same generated owner and artifact remain bound throughout. If the
  artifact declares code, it runs `code-stage` → lifecycle `deploy`
  (`--materializing-code --state-handoff`) → `code-finalize` before `apply`;
  otherwise it
  preserves the legacy deploy → apply path. The lifecycle deploy and
  code-finalize retain the lease through state apply, which releases it only
  after convergence metadata commits. A concurrent promotion is refused, while
  a crashed owner is recoverable after the bounded expiry. The first skeleton's
  lease row is a 300-second process-handoff window. Each live mutation process
  also holds a database advisory fence, so opaque hooks and filesystem walks
  cannot be overlapped by a recovered writer if the row TTL passes mid-call.
  Only `promotion-begin` creates or recovers a session; it records the latest
  begun owner/artifact identity, and every later phase must continue both that
  identity and its exact live row. The original owner therefore
  cannot revive an expired lease for a later phase, so an export that outlives
  the lease fails closed before code/state mutation rather than reusing an
  unprotected snapshot. The host only sequences the separate halves; it neither interprets the opaque code
  descriptor nor treats `revision_hash` or `code_revision` as the lease key.

  Lifecycle APIs and authored options share the canonical `options/core`
  entity even though they have separate writers. When activation,
  deactivation, or a theme switch actually runs, deploy records that entity's
  canonical hash immediately before and after the hook window inside the
  exact owner/artifact session. Before recording that handoff, deploy verifies
  each changed canonical record is lifecycle-managed or already exactly equals
  this artifact's non-`absent` desired record; an unrelated authored hook
  mutation stops the promotion before state apply. Apply compares three-way
  history against the pre-hook hash only if its fresh live snapshot still
  equals the recorded post-hook hash. A later target edit invalidates the
  handoff and takes the ordinary conflict path; no force flag is implied.

  It stops on the first non-zero phase and compensates with an exact,
  idempotent lease abort. A failed export is not presented as a usable
  checkpoint. Every successful checkpoint intentionally contains the temporary
  `promotion_lock` row, because the lease existed before export. A database
  import can replace that row and therefore cannot itself be serialized by a
  lock stored inside the imported database. Recovery first requires external
  maintenance/exclusion for every Duo writer, then four ordered commands:
  exact abort of the old owner/hash (idempotent), re-begin that owner/hash,
  `wp db import <checkpoint>`, and a final abort of the row restored by the
  import—even when import fails. The first abort refuses if a newer session
  superseded this checkpoint, instead of presenting an obsolete dump as a safe
  recovery source. After a code-enabled failure, first reconcile or
  restore code to its known pre-promotion revision; a database import alone is
  not a complete code-and-state rollback. On success it retains the checkpoint
  and prints the phase trace.

  Apply flags are forwarded to apply; `--force-code-mismatch`,
  `--force-code-drift`, and `--force-unresolved-refs` are also forwarded to
  deploy. Callers cannot supply
  `--repo`, `--compiled`, `--artifact-hash`, `--promotion-owner`,
  `--promotion-hold`, `--materializing-code`, or `--state-handoff`, because the
  host owns those boundaries. The force
  flags never bypass `code_revision_stale`; promotion resolves it by running
  the code phases before state apply. Site repos must ignore the operational
  directory:

  ```gitignore
  .duo/
  ```

  Deploy remains a distinct lifecycle window where activation hooks fire;
  apply remains the separately canary-armed, hook-free state window.

- **`duo pending <env>`** — runs `wp duo pending --repo=<repo_path>
  --format=json` (the review-queue scan: gate items from the loud-and-
  blocking classification check (including namespace-enumerated options,
  term-meta representation gaps, and version-pinned EAV keyspace gaps), plus
  journal-observed unclassified writes outside an owned option namespace
  — see [DESIGN.md §3.1](../DESIGN.md#31-layered-classification-policy-vs-conflation--opacity))
  and renders it as a table: `SECTION:KEY`, `PROPOSAL` (the journal's best
  guess, or `-` when there isn't one — e.g. when the journal is off, or the
  signal was too weak to propose), `EVIDENCE` (compact — `"3 posts
  (post,page); journal n=14 rest/admin"`), and `REF-HINT` (`"-> post #12
  'About'"` when the engine's ref-linter recognizes the value as a
  numeric id it can point at a specific entity). A secret-flagged item gets
  a prominent trailing `[SECRET: hard:<label>]` / `[SECRET: suspicious]`
  marker. Exit 0 always; prints "review queue is empty" when there's
  nothing to triage. Evidence includes owner candidates, representative
  value shapes, counts, and the reason capture is blocked where those are
  known; the journal enriches evidence but is not required for completeness
  on declared surfaces. `duo pending <env> --format=json` is a raw passthrough
  of the agent's own JSON (same precedent as `duo status` vs. `duo plan
  --format=json`) for scripting.

- **`duo classify <env>`** — interactive triage of the review queue, one
  item at a time, reading decisions from **stdin** (not `/dev/tty` — so
  it's pipe-testable: `printf 'r\n' | duo classify e1` drives it exactly
  like a keypress would). For each item it prints the same evidence/
  proposal/ref-hint/secret block `duo pending` shows, then prompts:

  | Key | Effect |
  |---|---|
  | Enter | accept the item's proposal (only offered when one exists) |
  | `a` / `r` / `e` / `d` / `m` | explicitly classify `authored` / `runtime` / `env` / `derived` / `managed` |
  | `s` | skip this item (leave it pending) |
  | `q` | quit — stop triaging and apply whatever was already decided |

  **The secret rule**: choosing (or accepting a proposal of) `authored` on
  a secret-flagged item never goes through on Enter alone — it prints a red
  warning and requires typing the literal word `allow` before that
  decision is added to the batch; anything else skips the item. This is
  deliberate and absolute: Enter-accept can never silently author a secret
  into git, matching the project's loud-and-blocking posture on everything
  else (§3.1.5).

  **Ref attachment**: an `authored` decision on an item carrying a
  `ref_hint` gets one more y/N prompt — "attach `ref=<kind>` to this
  classification?" — before moving on. Declining leaves the field authored
  but untyped.

  All decisions are batched into a **single** `wp duo classify --repo=<repo_path>
  --set=<section>:<key>=<class>[,ref=<kind>] […]` call at the end (not one
  call per item) — its output streams live and its exit code propagates.
  A final `N classified, M skipped.` line summarizes the session. Exit 0
  immediately with "review queue is empty" if there was nothing to triage.

- **`duo classify <env> --accept-proposals`** — non-interactive, for CI/
  scripting: accepts every item that has a proposal, exactly as proposed,
  in one batched call. The one exception is absolute: a secret-flagged item
  proposed `authored` is **never** auto-accepted — it's skipped loudly (its
  `section:key` and secret label printed to stderr) because that decision
  needs a human. Ref-hints are never auto-attached in this mode either
  (attaching a ref is the judgment call the interactive y/N prompt exists
  for). Exit 0 if the queue was empty or every proposal-bearing item got
  accepted; exit 2 if any secret-authored item had to be skipped, so a CI
  pipeline can tell "nothing to do" apart from "a human needs to look at
  this."

  ```
  duo classify e1 --accept-proposals
  ```

  <sub>Implementation note: all decisions travel in a single semicolon-
  joined `--set` value (`--set 'post_meta:foo=runtime;options:bar=authored,ref=post'`),
  never as repeated `--set=<spec>` flags — wp-cli's assoc-arg parser keeps
  only the *last* occurrence of a repeated flag, confirmed against
  `agent/src/Cli.php`'s `classify()` docblock. `cli/duo`'s
  `CLASSIFY_SET_MODE` constant is the one place that decision lives.</sub>

## The environment registry

An environment registry is a JSON object under an `"envs"` key, keyed by
environment name:

```json
{
  "envs": {
    "e1": {
      "transport": "docker",
      "compose_file": "sandbox/docker-compose.yml",
      "profile": "spikee",
      "service": "cli-e1",
      "repo_path": "/siterepo"
    },
    "stage": {
      "transport": "ssh",
      "host": "deploy@stage.example.com",
      "wp_path": "/var/www/html",
      "repo_path": "/srv/site"
    },
    "dev": {
      "transport": "local",
      "wp_path": "/var/www/html",
      "repo_path": "/home/me/site"
    }
  }
}
```

`repo_path` is required for every environment, on every transport — it's
the site-repo path **as seen from inside that environment** (a container
path, a remote path, or a local path), passed straight through as
`wp duo <verb> --repo=<repo_path>`.

Per-transport required keys:

| Transport | Required keys | Optional keys |
|---|---|---|
| `local` | `wp_path`, `repo_path` | — |
| `docker` | `compose_file`, `service`, `repo_path` | `profile` |
| `ssh` | `host`, `wp_path`, `repo_path` | `ssh_config` |

A missing required key is a loud, specific error naming the environment,
the key, and the transport — never a guess.

`ssh_config`, when present, is passed to both `ssh -F` and `scp -F` and may be
relative to the registry file that defined the environment. This is the
single place to configure a non-default port, identity, proxy jump, and
host-key policy without embedding shell options in `host`.

### Where the registry comes from

`duo` merges **two** optional files, found by walking upward from the
current directory (git-style — so it works from any subdirectory):

1. **`site.duo.json`** — the site repo's own policy file (see
   [spec/repo-format.md](../spec/repo-format.md)). Committable: the `envs`
   entries here should contain nothing secret (no passwords, no bare API
   tokens) since this file is meant to be shared with the whole team via
   git. This is the natural home for the list of environments that *exist*
   for this site (e.g. `dev`, `stage`, `prod`) and whatever about them is
   true for everyone.
2. **`.duo-envs.json`** *(gitignored)* — a machine-local overlay, same
   shape (`{"envs": {...}}`), for anything that's true only on this
   machine or shouldn't be committed: a local docker-compose file path, an
   ssh alias only you have configured, a sandbox-only environment nobody
   else needs.

**The overlay wins whole-entry, per environment name** — if `stage` exists
in both files, `.duo-envs.json`'s `stage` entirely replaces
`site.duo.json`'s (no per-key deep merge). This keeps the merge rule simple
and predictable: for any given environment, exactly one file is "the"
source of truth, and it's always the more machine-specific one when both
define it.

Relative filesystem paths inside an environment entry (currently just
`compose_file`) resolve against the directory of **whichever file defined
that entry** — not the current working directory — so a registry file
keeps working no matter where you invoke `duo` from.

Pass `--envs-file=<path>` to load the overlay from an explicit path instead
of searching for `.duo-envs.json`. `site.duo.json` discovery is unaffected
by this flag — it's specifically an override for the machine-local half of
the registry.

### Suggested `.gitignore` line

```
.duo-envs.json
.duo/
```

(Already added to this repo's `.gitignore` for the sandbox.)

## Transports

All three transports build a fully `escapeshellarg()`-escaped command
string and run it either streamed (`passthru`, exit code propagated — used
by `capture`/`plan`/`apply`) or captured with stdout and stderr collected
on **separate** pipes (`proc_open`, used by `doctor`/`status`, which parse
output — `docker compose run`'s own container-lifecycle chatter lands on
stderr, and merging the streams would corrupt the JSON `duo status` parses).

- **`local`**: `wp --path=<wp_path> duo <verb> --repo=<repo_path> …`
- **`docker`**: `docker compose -f <compose_file> [--profile <profile>] run --rm -T <service> wp duo <verb> --repo=<repo_path> …`
- **`ssh`**: `ssh <host> 'cd <wp_path> && wp duo <verb> --repo=<repo_path> …'`
  (the remote command is assembled with each part escaped, then the whole
  thing is escaped again as the single argument to `ssh`)

Raw (non-`wp`) commands, used only by `doctor`'s reachability, repo-path,
and `.duo-env-values.json` git-tracked checks, follow the same shape but run through `bash -c '<script>'` for
`docker` (so shell operators like `&&`/`[ -d … ]` work — `docker compose
run`'s trailing arguments are otherwise passed as the container's argv
directly, not interpreted by a shell) and directly for `local`/`ssh` (PHP's
`proc_open`/`passthru` already invoke `/bin/sh -c` for string commands, and
`ssh` already hands its command argument to the remote login shell).

## Env-bound value provisioning

DUO-3232. A manifest can classify an option `class: "env"` — a value that
is genuinely per-environment (a payment gateway API key, `siteurl`, an
`admin_email`) and must therefore **never** be captured or applied like an
ordinary authored value; doing so would let one environment's value
silently overwrite another's the next time someone runs `duo apply`. Every
`class: "env"` rule also carries a mandatory boolean `required`
(`Policy::validate_env_options()` refuses to load a manifest that omits
it): `true` means an operator must hand-provision this value on every
fresh environment (a genuine secret or site-identity value with no sane
default); `false` means it's plugin-internal bookkeeping that
self-populates the first time its owning plugin runs (a version marker, a
one-shot install-state flag) and is not worth checklisting — see any
shipped manifest's own `options` section for real examples of both.

Because env values are never captured, there is no repo-side record of
what any environment's values *should* be — only whether THIS
environment currently has *something* non-empty in each declared slot:

- **`duo plan` / `duo status`** surface an `env_missing` bucket: every
  declared `class: "env"` option whose live value is absent or an empty
  string on this environment, each tagged with its `required` flag. A
  *required* miss makes `duo status` exit non-zero ("not safe to
  promote"); an *optional* miss is listed for visibility only. This is a
  per-environment self-check, not a cross-environment diff — to compare
  what two environments actually have, run `duo plan <env>
  --format=json` against both and diff the two `env_missing` lists
  yourself.
- **`duo env-set <env> --name=<name> (--value=<value> | --stdin)`**
  provisions one value directly, bypassing capture/apply entirely
  (`Apply::set_env_option()`). It refuses any `--name` the loaded policy
  didn't declare `class: "env"`, refuses an option that declares
  `sub_keys` (a structured, plugin-managed blob — Yoast's `wpseo`,
  Polylang's `polylang` — that a bare string write would corrupt; every
  such option shipped today is `required: false` for exactly this
  reason), and refuses an empty value (which `env_missing` would
  immediately re-flag as still-missing). `--stdin` reads the value from
  STDIN with terminal echo disabled and is never printed back or logged
  (not `--prompt` — see the passthrough section above for why that name
  was unavailable); its own "value for '&lt;name&gt;': " prompt writes to
  STDERR, never STDOUT, so `--stdin --format=json` is still safe to pipe
  into a JSON parser. `--value` is scriptable but — like any other flag —
  lands in shell history and process listings, so prefer `--stdin` for
  anything actually secret when running interactively.

### `.duo-env-values.json` (optional, gitignored, not yet auto-consumed)

An operator provisioning several `class: "env"` options by hand may want
somewhere to keep track of what they set, without ever committing it. The
name `.duo-env-values.json` is reserved for exactly that: a flat
`{"option_name": "value", ...}` scratch file living next to
`site.duo.json` inside **one environment's own checkout** (not on the
orchestrator host — contrast `.duo-envs.json` above, which is
machine-local to wherever you *run* `duo` from and covers every
environment at once; this file, if it exists, lives on the target itself
and covers only that one environment). It ships in
`sandbox/site-repo.gitignore.template` (every managed site repo's own
`.gitignore`) and is checked by `duo doctor <env>`'s git-tracked hygiene
check (a tracked secrets file is a blocking failure, not an advisory
one). That check runs *inside* the target environment, so it needs a
`git` binary there to inspect tracked status with — most environments
materializing `wp duo` commands have no structural reason to carry one
(the agent itself never shells out to git), and this project's own
sandbox images verifiably don't, so the check degrades to an honest
advisory "could not verify" in that case rather than a false-clean PASS
— see `cli/src/Doctor.php`.

**Nothing in this codebase reads this file yet.** No `env-set` variant
loads it, and `agent/src/Secrets.php`'s own scanning never inspects it
either — that class exists to catch a secret-SHAPED value being captured
under the wrong classification from a *live WordPress environment*, and
this file is orchestrator/operator-side, never captured, so it was never
in scope for that scanner to begin with (documented explicitly in
`agent/src/Secrets.php`'s own docblock, not left as an implicit gap). The
reserved name and gitignore/doctor protection exist now, ahead of any
consumer, so that protection is already in place the day a batch-loader
(most naturally `wp duo env-set --from-file=.duo-env-values.json`,
agent-side, reading the file that's already sitting next to
`site.duo.json` on the same checkout — never an orchestrator-side loop
over remote single-sets) is added as a later, separately-scoped
convenience.

### Scope: options only, v1

`class: "env"` classification, `env_missing`, and `env-set` all operate on
**options only** in this first pass — never `post_meta`/`term_meta`, and
never a `sub_keys` carve-out's individual keys (those remain classified
independently under their own `class`, unaffected by their parent
option's `env` classification). `Policy::env_options()`'s own docblock
has the full reasoning: unlike options, meta classification is
interpreter-driven per post (`Policy::meta_rule_for_post()`), so "every
env-classified meta key across the whole install" has no well-defined,
enumerable answer the way a manifest's flat `options` map does. Every
real `class: "env"` value across every shipped manifest today is an
option — verified empirically, not assumed. A genuine need for
env-classified meta, if one ever surfaces, is separate, scoped follow-up
work, not something this pass tries to solve speculatively.

## Proposed spec addition

The following is proposed language for `spec/repo-format.md` (not applied —
spec/ is the team lead's; this is a suggestion for the `site.duo.json`
section):

> ### `envs` (optional)
>
> `site.duo.json` may declare an `"envs"` object, keyed by environment
> name, describing the environments that materialize this site repo for
> the `duo` orchestrator CLI (see [cli/README.md](../cli/README.md)). Each
> entry names a `transport` (`local`, `docker`, or `ssh`) and a
> `repo_path` — this site repo's path *as seen from inside that
> environment*. Entries here are shared via git and must contain no
> secrets (credentials, tokens, private hostnames an attacker could use);
> anything machine-specific or sensitive belongs instead in a gitignored,
> machine-local `.duo-envs.json` overlay next to it, which replaces
> same-named entries whole. `envs` is orchestrator convenience, not part of
> the branchable state contract — the agent's `wp duo …` commands
> (`spec/repo-format.md`'s actual subject) never read it.

A second suggestion, for wherever `spec/repo-format.md` documents a
manifest's `options` section and its `class` values (`authored`,
`runtime`, `derived`, `env`, `managed`) — unlike `envs` above, this one
*is* part of the branchable-state contract the agent itself interprets
(`agent/src/Policy.php`, `agent/src/Apply.php`), not orchestrator-only:

> #### `class: "env"` options (DUO-3232)
>
> An option classified `"env"` is genuinely per-environment — a payment
> gateway API key, `siteurl`, an `admin_email` — and is therefore excluded
> from capture and apply entirely, the same way `"runtime"` is, but for
> the opposite reason (`"runtime"` is excluded because it's disposable and
> not worth versioning; `"env"` is excluded because versioning it and
> replaying it onto another environment would be actively wrong). Every
> `"env"` rule **must** declare an explicit boolean `required` — there is
> no default, and a manifest omitting it fails to load
> (`Policy::validate_env_options()`): `true` means an operator must
> hand-provision a value on every fresh environment before it can be
> considered fully promoted; `false` means the value is plugin-internal
> bookkeeping that self-populates and is not worth an operator's
> attention. `wp duo plan`/`wp duo status` surface every currently-unset
> `"env"` option as an `env_missing` entry tagged with its `required`
> flag; `wp duo env-set` is the only sanctioned way to write one, and
> refuses any option name not declared `"env"` by the loaded policy.
>
> **Scope note, stated loudly rather than left implicit**: `"env"`
> classification applies to whole `options` entries only in this first
> pass. It does not extend to `post_meta`/`term_meta` (no enumerable,
> manifest-declared list of those exists the way a flat `options` map
> does — see `Policy::env_options()`'s own docblock), and a `sub_keys`
> carve-out's individual keys remain governed by their own `class`
> regardless of their parent option's `env` classification (Yoast's
> `wpseo.disableadvanced_meta` stays `"authored"` even though `wpseo`
> itself is `"env"`). Extending `"env"` to either is unscoped future work,
> not assumed or partially implemented here.

**Pre-existing text this makes stale, flagged for whoever applies the
above** (not touched directly — same reason as everything else on this
page): the "Manifests (registry format)" section's own example manifest
currently shows `"home": {"class": "env"}` and `"siteurl": {"class":
"env"}` with no `required` key. Both need `"required": true` added (the
real `manifests/core.json` this example is modeled on already has it) or
the example will no longer load under `validate_env_options()`.
