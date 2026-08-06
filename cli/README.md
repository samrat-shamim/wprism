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

`duo` is dependency-free PHP 8+: no composer, no vendored packages, no
WordPress required on the machine that runs it. Requires only a `php`
binary and, per environment, whatever the transport itself needs (`ssh`,
`docker compose`, or a local `wp-cli`).

## Usage

```
duo envs
duo doctor <env>
duo status <env>
duo capture <env> [extra wp-cli flags...]
duo plan    <env> [extra wp-cli flags...]
duo apply   <env> [extra wp-cli flags...]
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

- **`duo doctor <env>`** — four gated checks, each skipped (reported as a
  failure) once an earlier one fails, since a broken transport makes every
  later check meaningless noise:
  1. transport reachable (`echo` round-trips through the transport)
  2. WordPress installed (`wp core is-installed`)
  3. the duo agent is present (`wp eval` checks `class_exists('\Duo\Capture')`)
  4. `repo_path` exists and contains `site.duo.json`

  Exit 0 only if all four pass.

- **`duo status <env>`** — runs `wp duo plan --repo=<repo_path>
  --format=json` (note: `--format=json`, not `--json` — wp-cli's dispatcher
  rewrites a bare `--json` into `--format=json` before the command ever sees
  `$assoc['json']`; see the comments in `agent/src/Cli.php`) and renders a
  human summary: counts per plan bucket (create/update/adopt/unchanged/
  drift/conflict/collision/delete/code_mismatch), drift paths, blocked-
  delete reasons, code_mismatch findings (agent/src/Deploy.php's
  missing_in_code/outside_version_range checks — docs/proposals/
  code-half.md §3.2), and any plan-level warnings.

  Exit non-zero ("not safe to promote") if the plan contains any
  `conflict`, `collision`, or `code_mismatch` entry, any blocked delete, or
  any drift — drift is the one case `duo apply` itself does *not* refuse
  on (a drifted entity just folds into `update` once the repo side changes
  too, or stays `drift` otherwise), but `duo status` still reports it as
  not clean, since status answers "safe to promote?", not just "will apply
  refuse?" — capture first. Also non-zero if the underlying `wp duo plan`
  call itself failed or returned unparseable JSON. Plain warnings are
  rendered but never flip this by themselves — see the decision-matrix
  comment in `cli/src/PlanSummary.php::render()`.

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

- **`duo pending <env>`** — runs `wp duo pending --repo=<repo_path>
  --format=json` (the review-queue scan: gate items from the loud-and-
  blocking classification check, plus journal-observed unclassified writes
  — see [DESIGN.md §3.1](../DESIGN.md#31-layered-classification-policy-vs-conflation--opacity))
  and renders it as a table: `SECTION:KEY`, `PROPOSAL` (the journal's best
  guess, or `-` when there isn't one — e.g. when the journal is off, or the
  signal was too weak to propose), `EVIDENCE` (compact — `"3 posts
  (post,page); journal n=14 rest/admin"`), and `REF-HINT` (`"-> post #12
  'About'"` when the engine's ref-linter recognizes the value as a
  numeric id it can point at a specific entity). A secret-flagged item gets
  a prominent trailing `[SECRET: hard:<label>]` / `[SECRET: suspicious]`
  marker. Exit 0 always; prints "review queue is empty" when there's
  nothing to triage. `duo pending <env> --format=json` is a raw passthrough
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
| `ssh` | `host`, `wp_path`, `repo_path` | — |

A missing required key is a loud, specific error naming the environment,
the key, and the transport — never a guess.

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

Raw (non-`wp`) commands, used only by `doctor`'s reachability and repo-path
checks, follow the same shape but run through `bash -c '<script>'` for
`docker` (so shell operators like `&&`/`[ -d … ]` work — `docker compose
run`'s trailing arguments are otherwise passed as the container's argv
directly, not interpreted by a shell) and directly for `local`/`ssh` (PHP's
`proc_open`/`passthru` already invoke `/bin/sh -c` for string commands, and
`ssh` already hands its command argument to the remote login shell).

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
