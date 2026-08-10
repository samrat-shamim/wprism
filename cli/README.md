# duo — the orchestrator CLI

`duo` is the host-agnostic, multi-environment front end for the Duo agent.
Git stays git — branching, merging, and history all happen on the site repo
exactly as before. `duo` only adds two things on top of the per-environment
`wp duo capture|plan|explain|apply` commands (see [agent/src/Cli.php](../agent/src/Cli.php)):

1. **Environment drivers** — one narrow workflow boundary with local,
   container, and SSH transports, plus a closed capability report so Duo can
   refuse unsupported operations before it contacts the target.
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
duo env materialize <env> --from <production-env> --branch <ref> [--create] [--ttl <seconds>] [--format=json]
duo env reap <env> [--format=json]
duo manifest-validate <manifests-dir> [--manifest=<name>[,...]] [--pins=<name>[,...]|--all] [--site=<site-repo>] [--no-code] [--format=json]
duo manifest-validate --emit-schema
duo adapter list [--repo=<site-repo>] [--format=json]
duo adapter inspect <name> [--repo=<site-repo>] [--format=json]
duo adapter doctor [--repo=<site-repo>] [--format=json]
duo adapter-observe <env> [--out=<local-file>|--format=json]
duo doctor <env>
duo driver-capabilities <env> [--operation=<workflow>] [--format=json]
duo adopt  <env>
duo init   <env> [--yes]
duo status <env> [--category=<ids>] [--action=<buckets>] [--entity=<kinds>] [--limit=<1..200>]
duo capabilities <env> [--format=json]
duo capture <env> [--scope-contract=<local-path>] [extra wp-cli flags...]
duo plan    <env> [--scope-contract=<local-path>] [extra wp-cli flags...]
duo explain <env> <bucket>:<entity-key> [--format=json] [planning flags...]
duo apply   <env> [--scope-contract=<local-path>] [extra wp-cli flags...]
duo deploy  <env> [--force-code-mismatch] [--force-code-drift]
duo env-set <env> --name=<name> (--value=<value> | --stdin)
duo promote <env> [extra apply flags...]
duo pending <env>
duo classify <env> [--accept-proposals|--export-batch=<path>|--apply-batch=<path>]
duo coverage <env> [--format=json]
duo scope <env> --roots=<selectors> [--contract] [--format=json]
duo refresh <production-env> --production-ref=<ref> [--scope-contract=<local-path>] [--field-diff [--format=json]]
duo rebase <production-env> --production-ref=<ref> --new-branch=<name> [--scope-contract=<local-path>] [--strategy=manual|ours|theirs] [--resolve=<stable-id>=ours|theirs ...]
duo rebase <production-env> --production-ref=<ref> --new-branch=<name> [--field-resolution=<local-path>|--interactive]
duo rebase <production-env> --abort=<run-id>
duo -h | --help
```

Run `duo --help` for the full usage text (verbs, global flags, registry shape).

- **`duo envs`** — lists every environment in the merged registry with a
  one-line transport summary. Exit 0 if the registry has at least one
  environment (even if some environments' configs are individually invalid —
  those print as an inline `ERROR: …` row instead of failing the whole
  listing), exit 1 if no `envs` were found anywhere.

- **`duo manifest-validate <manifests-dir>`** — the one verb here that takes no
  environment, because it needs none: it runs the engine's real manifest
  validators (`agent/src/Policy.php`'s load-time battery) over a directory of
  manifest files with no WordPress, no database, and no transport. Each manifest
  is loaded on its own, then the requested pin set is co-loaded so the
  cross-manifest guards run too; engine refusals are surfaced verbatim with
  their own coordinates, a per-manifest row carrying that manifest's file path
  and the pin-set row carrying the paths of everything co-loaded.
  `--manifest=` narrows what is checked individually, `--pins=`/`--all` choose
  the co-loaded set, `--format=json` emits the report as
  `duo-manifest-validation/v1`, and `--emit-schema` prints the grammar document
  (`duo-manifest-grammar/v1`) read out of the engine's own closed vocabularies.
  `--site=<site-repo>` is optional but not cosmetic: two of those guards read
  `site.duo.json`'s policy half as input (a site-declared table extends the
  ref/token/ledger kind vocabulary; a site `policy.options` rule resolves an
  option two manifests declare differently), so without it a manifest that is
  valid on its real site can be refused here — and such a refusal is annotated
  as possibly site-resolvable rather than rewritten.
  Point it only at a manifests directory you trust as much as the agent's own:
  a declared `interpreter`/`regenerator` is resolved, and resolving one LOADS
  that PHP (top level plus constructor), which is the only way to check its
  class contract. `--no-code` is the escape for a first look at an unfamiliar
  out-of-tree package — every declaration is still validated, the code half is
  skipped, and the run says so in its header and in an explicit not-performed
  row rather than passing quietly.
  Exit 0 all valid, 1 any invalid, 2 usage/IO. An authoring aid, not a gate —
  it ends every run, passing or failing, with the checks that need a live
  target, plus the missing site half in no-`--site` mode and the skipped code
  half under `--no-code`, listed as `deferred`.
  See
  [docs/guides/adapter-authoring.md](../docs/guides/adapter-authoring.md#checking-the-grammar-offline).

- **`duo adapter-observe <env>`** — asks the configured target exactly once
  for a closed, canonical `duo-adapter-observation/v1` proposal-evidence
  projection. The host supplies only that environment's configured
  `repo_path`; there is no host-local `--repo` escape. The response is strict
  JSON with a recomputed observation hash, value redaction, and no target IDs,
  titles, paths, messages, SQL, credentials, or authored values. `--out=`
  retains the already-validated canonical document only at a new local path:
  it refuses an existing file, symlink, FIFO, or other directory entry rather
  than replacing evidence.
  The nested `catalog` is a deliberately lossy projection of the target's
  `duo-adapter-sources/v2` survey, not a claim to preserve the full
  `duo-adapter-catalog/v2` contract. This evidence is never authoritative
  AdapterDraft input and does not alter certification or registry claims.
  Normal plugin/provider registration and capability negotiation remain
  enabled so installed adapters are observable; third-party callbacks may have
  side effects before or during collection. Duo invokes no provider action and
  performs no explicit mutation after observer entry, while the report itself
  defers table semantics, apply/rollback, lifecycle changes, publication, and
  certification.

- **`duo adapter list|inspect|doctor`** — the installed-adapter catalog, and
  the other verb here that needs no environment. It reports what is installed
  across the two adapter sources a WordPress-free process can reach — the
  agent's own manifest library and, with `--repo=<site-repo>`, that
  repository's own `adapters/` overlay — where each adapter came from, the
  executable authority its own declarations reach, and what is wrong with any
  of it. No WordPress, no database, no transport.
  The engine has a THIRD source: one `duo-adapter.json` at the root of each
  ACTIVE plugin that bundles one. It lives in `WP_PLUGIN_DIR`, so only the
  target can see it — every report here carries a `sources` block marking each
  of the three scanned or not scanned and why, and `wp duo adapter-survey
  [--repo=<path>] [--format=json]` is the same survey run ON the target,
  emitting the same document with `command: "survey"`. An empty catalog is
  never a claim that no adapter is installed, only that none is installed in
  the sources this process could reach.
  `list` is one row per adapter: name, source, derived trust tier
  (`declarative_manifest`, `native_action`, `plugin_provider`,
  `compatibility_shim`) with `tier_basis` naming the exact declaration that
  produced it, reviewed disposition status (or `uncertified` for an
  out-of-tree adapter), and an isolated grammar verdict from the engine's real
  loader.
  `inspect <name>` adds the reviewed disposition entry, the generated registry
  claim (adapter digest, supported versions, operations, surfaces, explicit
  unsupported boundaries, evidence bundle), the providers the manifest
  requires with the capabilities each must advertise, and the verification
  facts that already exist — `evidence.status`, the claim's
  `plugin_execution.status`, and each cited test resolved against the bundle's
  own verdict. There is no verification score, and inventing one next to
  reviewed evidence is exactly what the certification separation refuses.
  `doctor` adds this repository's readiness blockers (with `--repo`) and every
  installed file the engine refuses to load — a shadowed adapter, an ambiguous
  identity, a case-confusable name, a symlink, a nested or near-miss `.json`,
  a reserved name — each as a ROW carrying the engine's own message, a stable
  code, and its remediation. That is the point of the verb: `duo adapter
  doctor` is what you can still run when the repository is in one of those
  states, because every other command refuses first. Every refusal row also
  names the source it is about and whether it refused that whole source or one
  adapter, and a separate `not_installed` block lists adapters that are on the
  machine and lost to a higher-precedence definition (sources rank
  `shipped > site > plugin`) — those print on every run and deliberately never
  change the exit code, because a correctly resolved shadow is precedence
  working rather than a fault. The report format is
  `duo-adapter-catalog/v2`.
  Exit 0 healthy, 1 anything surfaced, 2 usage/IO. Every run, passing or
  failing, ends with a `deferred` list: what needs a live target (plugin/theme
  state, provider negotiation, certification against one environment), the
  manifest-shipped PHP it names but deliberately never loads, and the
  cross-manifest guards that belong to a pin set rather than to one adapter.
  It never claims a live verdict.

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

- **`duo driver-capabilities <env> [--operation=<workflow>]`
  `[--format=json]`** — computes a target-free, versioned
  `duo-environment-driver-capabilities/v1` preflight. The same canonical
  report, readiness value, blocker list, and SHA-256 digest drive the human
  output, JSON output, exit status, and the workflow gate itself. This is a
  host capability contract, separate from plugin/adapter capability claims.

  Capability IDs are closed and deliberately granular: attaching to an
  existing environment is distinct from bootstrapping it; creating an
  environment is distinct from destroying one; database snapshots are
  distinct from media snapshots; and maintenance, URL, TTL, and operation
  receipts are never inferred from generic shell or WP-CLI access. Unknown
  operations and missing requirements are non-zero. A blocked workflow exits
  before its first raw, WP-CLI, SSH, Compose, upload, artifact-directory,
  lease, or checkpoint action.

  Built-in local, container, and SSH drivers all attach to configured
  pre-existing targets and use the same control/code/database proof
  requirements. SSH declares its explicit `adopt` upload/bootstrap path.
  Local declares that mechanism only when the exact bootstrap opt-in came from
  the untracked machine-local registry; this target-free declaration is not a
  target-safety attestation, and `adopt` still runs the separate eligibility
  report. Docker does not declare delivery. None of the built-ins claims host
  provisioning, destroy/TTL, media snapshot, maintenance-mode, URL mutation,
  or driver-owned operation receipts. Those stay visibly unsupported until a
  driver implements them; provider provisioning remains an optional driver
  extension rather than engine behavior.

- **`duo env materialize <env> --from <production-env> --branch <ref>`** —
  creates the default short-lived branch environment without collapsing its
  three separate contracts. `Refresh` first rebases the clean, currently
  checked-out branch against live production semantic truth in an isolated
  worktree. A separately configured host provider freezes the production
  snapshot session while that semantic export is produced, creates one
  coherent opaque DB/media snapshot set, and serves an immutable readback that
  binds the physical hashes to that exact semantic snapshot. The provider then
  attaches to the target (the default) or explicitly creates it (`--create`),
  restores the physical baseline, materializes the candidate repository
  commit, and restores the provider-owned target URL. Finally the ordinary
  promotion state machine consumes the exact already-compiled outer artifact
  under a deterministic operation owner; it never compiles a second release.
  A clean plan verifies convergence, and the receipt retains the apply receipt
  plus separate code and state release identities.

  Every provider request is canonical JSON over absolute direct argv, with no
  shell, a bounded timeout/output size, and redacted failure output. Source and
  target capability reports are checked together before the first snapshot or
  target mutation. Attach is never inferred to mean create; create is accepted
  only when the provider also promises exact receipt-backed destroy. An
  operation-scoped provider mutation fence is held across physical restore,
  repository/URL materialization, promotion, convergence, and TTL publication.
  Optional `--ttl=60..2592000` is a provider resource lease, not WordPress's
  database-local promotion lock; its exact generation/id/expiry is read back
  before the mutation fence is released. It is observable expiry metadata, not
  deletion authority: a provider must never autonomously destroy or detach the
  resource when that time passes. Cleanup happens only through an explicit,
  identity- and lease-fenced `duo env reap`. Retries reuse the immutable
  operation id, skip every journaled phase, and reconcile an uncertain phase
  with the same inputs and ownership tuple, so provider actions must be
  idempotent for that id. Convergence — and any promotion receipt reconciled
  from a lost controller response — is judged only from a *complete* `wp duo
  plan --format=json` envelope: a valid JSON document with a missing or
  non-list required bucket is refused by name, never counted as clean.

- **`duo env reap <env>`** — reads the latest immutable machine-local
  materialization journal, re-inspects the provider resource, and performs a
  provider-side compare-and-reap against its exact environment, resource,
  ownership lease, mutation fence, and optional TTL generation/id. Created
  resources require `environment.destroy`; attached resources require
  `environment.detach` and are never destroyed. A reused name, changed
  lease/generation, tampered
  receipt, or missing explicit capability refuses before the destructive call.
  Repeated reap returns the existing absence proof without another provider
  call. A changed TTL or mutation lease refuses before detach or destroy.

- **`duo adopt <env>`** — installs or updates this checkout's complete Duo
  agent and manifest library on a pre-existing SSH target, or performs the
  initial install on an explicitly opted-in machine-local target, creating a minimal
  core-only `site.duo.json` only when that file is absent, verifies
  the exact installed agent version, policy load, rollback authority, and
  blocking `duo doctor` rows before committing the filesystem transaction.
  Existing site policy is retained. The target needs
  no Git for adoption itself and the install does not rely on
  `DUO_MANIFESTS_DIR` surviving into an SSH login. The separate `duo init`
  workflow does require target Git. Local delivery is privileged: the exact
  `bootstrap` object below must come from the untracked machine-local overlay,
  static capability reporting remains target-free, and `adopt` then obtains a
  separate read-only eligibility proof before allocating or changing target
  paths. Local bootstrap refuses an already-installed Duo control plane;
  installed-target updates use the existing update path. Docker delivery remains unsupported. See the operator procedure and
  safety/update contract in [docs/adoption.md](../docs/adoption.md).

- **`duo init <env> [--yes]`** — asks the reachable target agent for a
  deterministic, read-only proposal covering platform facts, active code,
  certified adapters, authored scope, media availability, and value-redacted
  risk surfaces. It refuses unsupported boundaries before mutation. On
  confirmation it rechecks the proposal digest under the target's init and
  publication locks, verifies or creates a target-owned Git worktree, and
  publishes separate code and canonical state/media baselines. The final
  status proof is limited to selected managed scope. Init does not install
  WordPress or deliver the agent; SSH and explicitly authorized local targets
  use `duo adopt` first, while Docker targets must already expose the agent
  through their control plane.

- **`duo status <env>`** — runs `wp duo plan --repo=<repo_path>
  --format=json` (note: `--format=json`, not `--json` — wp-cli's dispatcher
  rewrites a bare `--json` into `--format=json` before the command ever sees
  `$assoc['json']`; see the comments in `agent/src/Cli.php`) and renders a
  human summary: counts per plan bucket (create/update/adopt/unchanged/
  drift/conflict/collision/delete/code_mismatch/code_drift/incomplete_apply/
  incomplete_lifecycle/regen_pending/env_missing/adapter_dispositions), drift paths, blocked-delete
  reasons, incomplete_lifecycle recovery receipts (an unresolved pre-hook
  lifecycle boundary requiring restoration of the exact database checkpoint),
  code_mismatch and code_drift findings (the latter is an installed code
  version/provenance change after Duo's last trusted observation),
  regen_pending entries (a derived table with a hard per-entity
  availability dependency — DUO-3234, e.g. TEC's tec_occurrences — whose
  post-apply verification failed and hasn't yet resolved), env_missing
  entries (a manifest-declared `class: "env"` option unset on this
  environment — DUO-3232, see "Env-bound value provisioning" below), and
  any plan-level warnings.

  Entity rows that carry an authored WordPress display name — a post's
  `title` front matter, a term's or menu's `name` — expose it as the row's
  `title` key in plan JSON (DUO-3345). `wp duo plan`'s own human output
  prints it in single quotes after the repository path on every itemized
  row. `duo status` itemizes only rows that demand a decision — drift,
  conflict, collision, blocked/conflicted deletes — so the name appears
  here exactly on those rows; a clean create/update batch still renders as
  counts alone on the summary line. Rows without an authored name
  (options, sidebars, typed tables, tombstones) render exactly as before;
  the name is never guessed or derived.

  New agents also add an optional `category_summary` projection with format
  `duo-plan-category-summary/v1`. It keeps the detailed plan untouched and
  reports nine fixed, ordered, overlapping facets—code, lifecycle, authored
  state, generated effects, media, secrets, environment state, capabilities,
  and deletions—using only closed identifiers and non-negative counts. Empty
  facets are JSON objects, and secrets carry only `visibility: "redacted"`.
  The public product word `generated` maps explicitly to the shipped
  `derived` manifest/wire class; no `generated` class is introduced. Nested
  menu-item, widget, and option deletion candidates come from the same target
  snapshot as the plan rather than a later query. Older agents omit this
  projection, and the host does not guess it. A malformed or absent optional
  projection is omitted from human output and never changes plan readiness,
  completeness, promotion, or convergence; the detailed buckets remain the
  sole authority.

  An explicit `--category=<csv>`, `--action=<csv>`, `--entity=<csv>`, or
  canonical `--limit=<1..200>` on `duo status` (or forwarded through
  `duo plan`) requests the additive `duo-plan-view/v1` display projection.
  It leaves every detailed plan bucket in the JSON envelope unchanged, carries
  `authoritative: false`, and contains only closed bucket/entity/category
  facets, a safety bit, and an opaque hashed explain selector. It has no raw
  title, path, value, secret, target id, source position, text search, or
  plugin-specific engine filter. The selector resolves one UUID within the
  full bucket, while presentation order is fixed action rank then UUID bytes;
  authoritative source order remains untouched. CSV values are exact closed
  tokens (OR within each dimension, AND across dimensions), deduped into
  vocabulary order. An explicit view defaults to and caps ordinary rows at
  200; safety rows (drift, conflict, collision, delete conflict, and blocked
  deletes) and global diagnostics bypass filters and the cap. A category view
  additionally requires the same-snapshot category summary. Status forwards
  one normalized request in its single full-plan call and fails closed with
  `plan_view_unavailable` if the requested projection is absent, malformed, or
  cannot be tied to that complete envelope; it never makes a second plan call
  or guesses a filtered result. No-option JSON and normal behavior remain
  compatible; filtered direct-plan rows and host status plan-row labels
  normalize C0/DEL controls to one line.

  Exit non-zero ("not safe to promote") if the plan contains any
  `conflict`, `collision`, `code_mismatch`, or `code_drift` entry, any
  blocked delete, any ordinary state drift, a retained `incomplete_apply`
  marker, an `incomplete_lifecycle` receipt, a `regen_pending` entry, or a
  **required** `env_missing` entry, or a pinned manifest whose external
  disposition is `experimental` or `excluded`. An `incomplete_lifecycle` receipt requires
  restoring the exact pre-lifecycle database checkpoint; force flags cannot
  bypass it.
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

- **`duo explain <env> <selector> [--format=json]`** — rebuilds the current
  plan under a strict observation boundary and traces one itemized entity row
  through its compiled source shape, effective policy/manifest declarations,
  outbound declared reference edges, exact rebuild surfaces, selected
  structured actions, and convergence verifier. Run human `duo plan <env>`
  and copy its indented, hash-safe `EXPLAIN` selector. JSON uses the separate
  `format:"duo-explain/v1"` contract; plan/status JSON remains unchanged.

  Explain is observational: it asserts an already-provisioned ledger and a
  coherent target snapshot, and refuses if identity repair is required or an
  attachment is not locally observable without its offload hook. It does not
  prune or repair the ledger, negotiate providers, invoke native/provider or
  attachment-offload hooks, or write target state. Action rows mean “this entity
  contributes to this declaration”; availability is deliberately
  `not_checked`, because apply negotiates before mutation. Canonical values,
  raw entity keys, repository paths, target-local ids, action arguments,
  provider receipts, and exception detail are omitted. Global plan sections
  are not selectors; use plan/status for lifecycle, code, capability, and
  whole-plan findings.

- **`duo capabilities <env> [--operation=<op>] [--surface=<surface>]`
  `[--revision=<sha>] [--format=json]`** — resolves the repository's exact
  manifest pins against [the generated capability registry](../manifests/capabilities/registry.json).
  It evaluates the requested platform revision, target WordPress/PHP/database
  and plugin/theme versions, operation, and exact state surface against one
  current content-addressed evidence bundle. Output separates unmodified
  plugin execution from branchable authored-state scope and gives structured
  blocker codes for unsupported, experimental, expired-evidence,
  version-mismatch, and multisite cases. The agent-level `wp duo capabilities
  --all` reports the complete shipped library. `duo status`, host
  deploy/promote, and `make release-gate` consume the same generated claims.

- **`duo plan|explain|apply <env> [flags...]`** — streams the corresponding
  `wp duo plan|explain|apply --repo=<repo_path> [flags...]` command for that
  environment. Ordinary flags are forwarded verbatim — e.g.:

  ```
  duo apply e2 --adopt-by-slug=terms --default-author=admin --with-deletes
  ```

  `--scope-contract=<local-path>` is the one deliberate exception for plan
  and apply: the host validates that local canonical file with the engine
  parser and sends only its normalized selectors and `scope_hash` as compact
  evidence. Scoped plan is strict read-only observation; it may inspect a
  selected provider's identity/capabilities but invokes no effect. Scoped apply creates
  a separate target/lease-bound `duo-scoped-mutation-authority/v1`, journals
  authored and native/provider effects under `duo-scoped-apply-session/v1`,
  reconciles lost and already-journaled effects by exact operation ID/input
  hash, and runs a fresh bounded verifier which opens and compares the exact
  active session rather than trusting caller-supplied roots. It advances selected ledger rows plus its terminal
  receipt only; the receipt binds their post-finalization identity-map root,
  while global `applied_revision` and unrelated recovery debt are untouched.
  Full plan/apply refuse while that session is nonterminal.
  Triggerless actions, legacy regenerators, environment-local provider
  deletion/reparent context channels, attachment metadata generation,
  code/lifecycle work, promote, and rollback are not silently widened into
  this slice; they refuse or remain whole-revision operations.

  stdout/stderr stream live (not buffered/reformatted) and the exit code is
  exactly the agent's exit code. When `--format=json` reaches the agent, every
  agent command that advertises `--format=json` answers with one JSON record on
  stdout and a non-zero exit — the whole set, not an enumerated subset, so a
  missing or contradictory argument is as machine-readable as a policy gate:

  ```json
  {
    "format": "duo-command-refusal/v1",
    "ok": false,
    "command": "capture",
    "error": "incomplete_state_discovery",
    "reason_code": "incomplete_state_discovery",
    "message": "capture found state that has no reviewed classification",
    "remediation": "review the diagnostics with duo pending, then classify or exclude every named surface before another capture"
  }
  ```

  Typed repository/code diagnostics retain their existing `error` and safe
  `diagnostics` fields unchanged inside that common envelope. If any nested
  diagnostic field is sensitive, the complete diagnostic batch is omitted,
  the stable `error` remains, and `details_redacted: true` records the
  refusal. Query- or fragment-bearing absolute URIs are conservatively
  classified as sensitive refusal evidence. Ordinary human mode is unchanged.
  An expected gate contributes only deliberately reviewed fields through a
  typed refusal or an established typed compiler diagnostic. The
  human-facing `duo: ` prefix is never machine-publication authority: every
  unclassified Throwable contributes none of its message, cause, path,
  login, or trace and instead sets `details_redacted: true` (DUO-3404). Known
  uncertain-commit and ambiguous-publication refusals explicitly say not to
  retry or discard retained recovery evidence.
  The host uses the same envelope if environment or driver preflight refuses
  `capture`, `plan`, or `apply` before the agent can run. Integrations must
  branch on the finite `reason_code` (the compatibility `error` has the same
  value), not parse prose. The claim is now closed rather than enumerated:
  every command advertising `--format=json` refuses this way, so there is no
  JSON-capable command left with a private refusal shape. `policy-to-manifest`
  and `manifest-pin` are the deliberate non-members — they advertise no
  `--format`, always print one canonical JSON document, and refuse human-only,
  so their refusal reads as an empty stdout with a non-zero exit.

  Detect a refusal by the payload, not by the exit code: branch on the
  top-level `format` field (or simply on stdout being a JSON *object* where
  the command's success shape is an array). A non-zero exit is not by itself
  a refusal — `lint` exits 1 with a findings **array** on a successful scan,
  by design — and a zero exit is never a refusal.

- **`duo capture <env> [--scope-contract=<local-path>] [flags...]`** — retains
  the same streaming agent command, but the optional contract path is consumed
  by the host and is never forwarded to the target. The host validates the
  canonical `duo-scope-contract/v1` with the engine parser, then sends only its
  normalized selectors and hash through the isolated control plane. The target
  recompiles and re-resolves the contract before observation. A bounded capture
  publishes a strict full-tree overlay: selected rows may change, while every
  excluded state/tombstone and unrelated media byte comes from the current
  repository exactly. It performs no identity minting or global stale-map
  pruning. A selected live deletion still needs the ordinary capability and
  must not strand an excluded inbound referrer; selected tombstone resurrection
  is refused. The complete target observation is checked before projection,
  and `all` remains strict source-bound state authority rather than enabling
  target identity minting. Media is authorized only by selected attachment records. New blobs are
  verified off-source and the original artifact is re-associated immediately
  before publication. Scoped capture refuses `--out`; legacy unscoped
  output-only capture is unchanged. Publication and crash recovery keep using
  the existing sealed full-candidate protocol.

- **`duo scope <env> --roots=<selectors> [--contract]`** — resolves a
  target-independent closure from explicit live roots. The default remains
  the human/`duo-scope/v1` preview. `--contract` instead emits canonical,
  self-verifying `duo-scope-contract/v1` evidence: normalized selectors,
  resolved live/tombstone identities, closure/inbound/excluded byte hashes,
  filtered uploads/media, exact eligible surfaces, and only potential
  action/provider/effect declarations. It is not a plan, provider
  negotiation, guard witness, or mutation authorization. `all` includes every
  compiled tombstone; a bounded deletion-intent row is named only as
  `tombstone:<uuid>`. The host runs both modes through the isolated Duo
  control plane (`--exec`, `--skip-plugins`, `--skip-themes`), so ordinary
  plugins, themes, and MU code cannot run before the read-only compile.
  Direct `wp duo scope --contract` without that isolated bootstrap refuses.

- **`duo refresh <production-env> --production-ref=<ref>
  [--scope-contract=<local-path>]`** — gets `P` only
  through `wp duo refresh-export --repo=<repo_path> --format=json`; it never
  uses capture/apply/promote or treats a Git tree as live production truth.
  The target repo’s HEAD must equal the locally resolved production ref and
  must have no tracked changes before *and after* export. The exporter’s
  completed code descriptor must also match the compiler result for that ref:
  descriptor bytes prove deployed code, while the ref proves topology. The
  resulting B/P/W plan is persisted immutably under the repository’s Git
  common-dir (`duo-refresh/plans/`), so orchestration evidence never dirties
  canonical state or requires a `.gitignore` rule. With a scope contract, the
  target associates it with that exact clean production ref and exports only
  selected state/media; everything else is explicitly `omitted_not_absent`.
  The planner treats those omissions as B, reports only in-scope conflicts,
  and records the exact contract/hash in its immutable plan.

  `--field-diff` is an explicit, unscoped-only opt-in. It leaves the private
  `duo-refresh-plan/v1` and its `plan_hash` unchanged, then writes a separate
  immutable `duo-refresh-field-diff/v1` projection under
  `duo-refresh/field-diffs/<diff_hash>.json`. The projection is
  display-only/non-authorizing and value-free: it contains opaque selectors,
  closed entity and field labels, change categories, B/P/W presence/equality
  relations, role names, and binding hashes—never a path, stable identity,
  B/P/W literal, per-value hash, body, meta, option, or user value. It
  decomposes only ordinary plan entries whose B/P/W record category is
  `conflicting`; branch-only, production-only, and compatible rows remain in
  the ordinary private plan/counts because they need no field choice.
  `--format=json` is available only with
  `--field-diff` and emits exactly that one redacted object (or the standard
  `duo-command-refusal/v1` envelope). Human output similarly says values are
  omitted. For each reported change, the closed relation gives B/P/W as
  `present`, `tombstone`, or `absent` and pairwise `same`/`different` evidence.
  For eligible fields all three roles are present: `production-only` means
  B=W≠P, `branch-only` means B=P≠W, `compatible` means P=W≠B, and
  `conflicting` means the three verified group values differ. Scalar equality
  is canonical comparison evidence (`"base"` equals `"\\u0062ase"`, and `1`
  equals `1.0`); exact source token bytes remain private and are never
  rewritten. Object/list values are not normalized. Atomic records use the
  same relation vocabulary over record type/semantic evidence without
  publishing that evidence.

  The v1 merge surface is deliberately narrow. It can independently compose
  ordinary post scalar groups (`author`, parent/order/comment/ping status,
  excerpt, title, coupled publication fields, and the coupled modification
  pair) and term `name`, `description`, and `parent`. Post body changes,
  attachments/media, menus, sidebars, options, user-meta, typed tables,
  tombstones, and opaque containers stay one record choice. A live B record
  with an absent P or W side refuses field mode before a diff or choice is
  published; use the legacy whole-record resolver for that absence. Field mode
  also refuses scoped plans or missing/skewed B/P/W policy evidence rather
  than guessing whether a derived field is authored.

- **`duo rebase <production-env> --production-ref=<ref> --new-branch=<name>
  [--scope-contract=<local-path>]`**
  re-exports production immediately before materialization and refuses if its
  snapshot hash changes. It uses a disposable local worktree and atomically
  creates only a new ref after semantic state validation; it never resets,
  checks out, or overwrites the source branch. Conflicts stay explicit by
  default (`--strategy=manual`); `--strategy=ours|theirs` and repeatable
  `--resolve=<stable-id>=ours|theirs` are explicit, journal-bound choices.
  An unresolved planner receipt creates no branch. `--abort=<run-id>` removes
  only a retained journal-owned candidate worktree. Scoped materialization
  starts from exact W bytes, replaces selected whole records only, preserves
  excluded state/tombstones/media byte-for-byte, and refuses closure outside
  the contract. It is deliberately state-only: W's code and ancestry remain
  unchanged. Plan/apply/verification accept this v1 evidence only by minting
  the separate target-bound authority/session described above; promote,
  lifecycle/code materialization, and rollback do not accept it.

  The legacy whole-record `--strategy`/`--resolve` contract remains unchanged.
  Alternatively, an unscoped run may supply
  `--field-resolution=<local-path>` naming a non-empty, at-most-1 MiB regular
  non-symlink canonical JSON file, or use `--interactive`; neither may be
  mixed with any `--strategy` spelling or `--resolve`. A field resolution is a
  separate `duo-refresh-field-resolution/v1` list of complete opaque
  field-or-record selectors bound to the exact plan, production snapshot, and
  redacted diff. Interactive mode accepts `b`/`branch` (ours) and
  `p`/`production` (theirs). It requires TTY stdin and stdout; as an explicit
  local-only reveal it may show one bounded C0/DEL-safe authored title/name or
  path fallback beside the closed selector. That transient label is never
  written to JSON, the diff, resolution, run, or receipt. `q` or EOF exits 2
  before a run record, candidate worktree, branch, or ref is created. The
  preview names every automatic branch/production decision (including changed
  non-conflicting private plan rows) and every manual conflict before
  prompting: `production-only` uses production, while `branch-only` and
  `compatible` retain the exact branch-byte scaffold. Only conflicts add
  resolution choices; pipes use `--field-resolution` instead.
  The ordinary private plan and value-free/redacted diff may already exist as
  immutable planning artifacts; cancellation creates no candidate evidence.
  A field splice uses exact verified source bytes with branch bytes as the
  scaffold; it never decode/re-encodes a hybrid document. After code-only
  rebase and before that splice, a fresh policy-only worker verifies the
  candidate policy is still the reviewed branch/production policy.

### Refresh semantic-planner contract

`cli/src/Refresh.php` has no plugin-specific or raw-Git state merge logic. A
`Duo\Orchestrator\RefreshPlan` implementation provides these static methods:

1. `normalizeProductionSnapshot(array $export): array` validates and
   canonicalizes `duo-refresh-production/v1` including semantic records,
   deletions, media, policy identity, and completed code evidence.
2. `compileGitWorktree(string $path, string $commit, string $role): array`
   compiles offline using the repository compiler. Roles are `base`, `branch`,
   and `production-code`; the latter exposes code identity only, never P.
3. `assertProductionCodeMatches(array $production, array $productionCode): void`
   proves exporter/target compiler metadata and completed descriptor/revision
   match the exact production ref.
4. `plan(array $base, array $production, array $branch, array $context): array`
   and `normalizePlan(array $plan): array` emit a canonical, hash-bound
   `duo-refresh-plan/v1` that includes the supplied B/P/W context.
5. `materialize(array $plan, string $worktree, array $resolution): array` and
   `validateMaterialization(array $receipt, array $plan, string $worktree): void`
   resolve only canonical state/media, reject unhandled stable conflicts,
   strict-compile, and return `duo-refresh-materialization/v1` with matching
   plan hash and `resolved: true`.
6. The optional field seam is separate from the plan: `fieldDiff(...)` emits
   the redacted projection plus process-local exact-span bundle and
   `validateFieldDiff(...)` accepts only its closed, hash-bound public shape;
   `readFieldResolution(...)`/`interactiveFieldResolution(...)` normalize one
   immutable value-free resolution; `interactiveFieldPresentation(...)` is a
   transient TTY-only private label/automatic-preview seam and never returns
   public evidence; `fieldDiffPolicyFromGitWorktree(...)` and
   `assertFieldCandidatePolicy(...)` recheck candidate policy after code
   replay; and `materializeFieldResolved(...)` returns the normal receipt
   additionally bound to the public `field_diff_hash` and
   `field_resolution_hash`.

The host rejects any materializer change outside `state/` and `media/`; native
Git code conflicts remain in the disposable worktree. Plugin semantics belong
in manifests, native actions, or plugin-owned providers—not this shell.

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
  `wp duo code-stage` → fresh-process lifecycle retirement → fresh-process
  lifecycle activation → `wp duo code-finalize`. It does not interpret
  descriptor fields or mutate
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
  artifact declares code, it runs `code-stage` → lifecycle retirement →
  fresh-process lifecycle activation (`--materializing-code --state-handoff`)
  → `code-finalize` before `apply`;
  otherwise it runs lifecycle retirement → fresh-process activation → apply
  without code materialization. The lifecycle phases and code-finalize retain
  the lease through state apply, which releases it only
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
  Each successful retire/activate process—including an explicit no-op—also
  appends an ordered receipt to that exact owner/artifact session. Code-finalize
  requires both receipts, so a caller cannot turn a merely staged payload into
  a completed `code_revision` by skipping WordPress lifecycle.

  Fatal-safe control commands (`compile`, `code-stage`, `code-finalize`, lease
  begin/abort, and recovery import) register their isolated agent loader at
  WP-CLI's `after_wp_config_load` boundary. They prove the effective content/MU
  layout before hiding user MU code. The v0 control plane accepts only the
  standard `wp-content/mu-plugins` layout with no explicit `WPMU_PLUGIN_DIR` and
  no `SUNRISE`; those configurations fail during compile before checkpoint or
  target mutation. Supporting Bedrock/custom content roots requires a future
  explicit layout/agent-locator contract, not path guessing.

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

  The post-hook handoff is not the first receipt. Immediately before any
  mutating lifecycle API, deploy durably records the entity, phase, and
  canonical pre-hook hash in the exact `promotion_session`. Successful
  retirement/activation consumes it atomically with the pending/final handoff.
  An exception, fatal, timeout, or process loss leaves it unresolved because a
  hook may already have committed authored state. Apply, lifecycle, code-stage,
  and code-finalize refuse it, and `promotion-begin` will not let a different
  owner/artifact overwrite the recovery identity—even on a first sync with no
  `duo_state` base. `duo status` exposes this as non-zero
  `INCOMPLETE_LIFECYCLE`. Only the exact original owner/artifact may re-begin
  for the documented checkpoint import sequence; it cannot continue a
  materializer, lifecycle, or apply phase while the receipt remains.

  It stops on the first non-zero phase and compensates with an exact,
  idempotent lease abort. A failed export is not presented as a usable
  checkpoint. Every successful checkpoint intentionally contains the temporary
  `promotion_lock` row, because the lease existed before export. A database
  import can replace that row and therefore cannot itself be serialized by a
  lock stored inside the imported database. Recovery first requires external
  maintenance/exclusion for every Duo writer, then four ordered commands:
  exact abort of the old owner/hash (idempotent), re-begin that owner/hash,
  a fatal-safe isolated `wp db import <checkpoint>` which skips plugins,
  themes, and user MU code, and a final abort of the row restored by the
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

- **`duo classify <env> --export-batch=<path>` / `--apply-batch=<path>`** —
  reviewed bulk triage for an aged site's first queue, where historical writes
  cannot have journal proposals. Export writes a value-redacted JSON artifact:
  every row retains the pending evidence and has editable `class`, `ref`,
  `cast`, and `allow_secret` fields. It refuses to overwrite an existing file.
  Fill every `decisions[].class`, review any ref/cast and secret override, then
  apply the same path. Apply fetches the live queue again and verifies the
  artifact's environment and SHA-256 binding before opening the one batched
  remote policy write. A partial review, changed queue, unsupported
  manifest/schema surface, malformed rule, or unacknowledged authored secret
  is a mutation-free refusal. If a valid decision exposes a new pending item,
  apply reports that next queue and exits 2; export and review a new batch.

  ```sh
  duo coverage production --format=json > coverage.json
  duo classify production --export-batch=production-review.json
  $EDITOR production-review.json
  duo classify production --apply-batch=production-review.json
  duo pending production
  ```

  The queue hash deliberately binds evidence as well as identities. Do not
  hand-edit `queue_sha256`: when the site changes, export a fresh artifact so
  the reviewed facts and the applied decisions stay the same transaction in
  the operator's reasoning.

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
      "repo_path": "/home/me/site",
      "bootstrap": {
        "format": "duo-local-control-plane/v1"
      }
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
| `local` | `wp_path`, `repo_path` | machine-local-only exact `bootstrap: {"format":"duo-local-control-plane/v1"}` |
| `docker` | `compose_file`, `service`, `repo_path` | `profile` |
| `ssh` | `host`, `wp_path`, `repo_path` | `ssh_config`, paired `rollback_key_id` + `rollback_signing_key`, `rollback_recovery`, `verified_rollback` |

A missing required key is a loud, specific error naming the environment,
the key, and the transport — never a guess.

The local `bootstrap` member grants only the delivery mechanism. It is accepted
only from an untracked `.duo-envs.json` entry whose provenance is assigned by
the registry loader; the same bytes in checked-in `site.duo.json` remain
unsupported. A present bootstrap object is closed and exact—`null`, another
format, or any extra key is a configuration error. `duo driver-capabilities`
does not contact WordPress. The later `duo adopt` command emits and binds a
`duo-bootstrap-eligibility/v1` report for the exact target before installation.

### Optional branch-environment provider

Physical production snapshots, resource creation/cleanup, URLs, and host TTLs
are privileged host operations, not transport primitives. An environment may
therefore add this exact block only in the machine-local `.duo-envs.json`
entry (checked-in `site.duo.json` is rejected even if it tries to forge loader
provenance):

```json
{
  "environment_provider": {
    "command": ["/absolute/path/to/provider", "--site=example"],
    "timeout_seconds": 30
  }
}
```

The command receives one canonical
`duo-branch-environment-provider-request/v1` object on stdin and must return
one canonical `duo-branch-environment-provider-response/v1` object on stdout.
Protocol 1 has a closed capability vocabulary:

```
snapshot.set.prepare      snapshot.set.create      snapshot.set.read
snapshot.set.abort        snapshot.set.restore
environment.inspect       environment.attach       environment.create
environment.destroy       environment.detach       environment.ttl
environment.ttl.read      environment.mutation.acquire
environment.mutation.read environment.mutation.release
environment.url.discover  environment.url.set      repository.materialize
operation.receipts
```

Operation responses contain only opaque IDs/digests and redacted operational
evidence—never database/media bytes, credentials, signed URLs, or production
PII. `snapshot-prepare` acquires a provider-owned source freeze and returns one
operation-bound session/lease receipt. While that freeze is held, Duo exports
semantic production truth; `snapshot-create` consumes the same session and
returns one `snapshot_set_id` binding database and media hashes, retention
evidence, source identity, and that semantic snapshot hash. `snapshot-read`
must return the immutable set before restore; `snapshot-abort` idempotently
releases an unfinished session. There is no raw or independently timed DB/media
fallback.

`mutation-acquire` obtains an exclusive operation-scoped target fence before
the first target mutation. `mutation-read` reconciles it after an interruption,
and `mutation-release` publishes an idempotent release receipt only after
promotion convergence and TTL readback. Only that held-to-released transition
may mint a new mutation receipt; every held or released `mutation-read` must
return the exact receipt already journaled for its current state. Every
mutating target request carries
`expected_environment_identity`, `expected_resource_id`, `expected_lease_id`,
`expected_lease_generation`, and `expected_ownership_receipt_sha256`; the
provider must also enforce the held mutation fence tuple. `ttl-set`/`ttl-read`
publish and verify expiry metadata only; they must not schedule or perform
automatic destruction. Reap compares both leases before its explicit destroy
or detach. The host journal lives under Git's common directory at
`duo-environments/` with mode-0600 immutable run/event records; it is
operational recovery state and never canonical branch state.

`ssh_config`, when present, is passed to both `ssh -F` and `scp -F` and may be
relative to the registry file that defined the environment. This is the
single place to configure a non-default port, identity, proxy jump, and
host-key policy without embedding shell options in `host`.

`rollback_key_id` and `rollback_signing_key` are optional as a pair. The key
path resolves relative to the registry file, must be a regular mode-`0600`
file, and contains canonical base64 Ed25519 secret-key bytes. Keep it in the
gitignored machine-local overlay. `duo adopt` derives and installs only its
public key under `<repo_path>/.duo/control/public-keys/`; a key id is immutable,
so rotation uses a new id. The controller secret is never copied to the host.

SSH adoption also installs the database-independent recovery runtime under
`<repo_path>/.duo/control/recovery-runtime/` and creates one stable external
target identity. `duo status` verifies the active signed receipt and complete
event hash chain. An invalid chain or any active nonterminal state is
non-green; only `committed` and `rolled_back` active generations are green.
`duo deploy` and `duo promote` refuse target mutation while that external
authority is invalid or nonterminal. A host adopted before this runtime emits
a manual-recovery warning and retains the operator-directed behavior.

`rollback_recovery` configures a target-owned exclusion provider plus exact
`code_restore`, `database_restore`, `prior_verify`, and `storage_restore` argv
adapters. Its optional `checkpoint_provider` argv enables encrypted database
checkpoint preparation and the stricter database/prior-verifier execution
path. Its optional `code_release_provider` argv enables off-target-built,
immutable descriptor-bound releases plus signed-journal `code_select` and
atomic `code_restore`; without it, code recovery is explicitly manual.
Its optional `upload_provider` prepares encrypted local/offload before-images,
adds signed-journal `storage_apply`, and makes `storage_restore` enforce exact
absence plus fresh prior-inventory verification; without it, upload recovery
is explicitly manual.
Its optional `effect_provider` prepares the compiled lifecycle/rebuild effect
inventory, receipt outboxes, and pinned inverse inputs, then enables
`effects_inverse` with fresh prior readback; without it, effect recovery is
explicitly manual.
`verified_rollback` is controller-only policy and contains exactly
`claim_ttl_seconds` (30..3600), `encryption_key_id` (the external KMS/provider
label, never key material), and `retention_seconds` (60..31536000). It is not
copied into the adopted recovery runtime. When this policy and all four
checkpoint/code/upload/effect providers are configured and pass runtime
preflight, `duo promote` selects the automatic profile by those capabilities,
binds the compiled plan's exact code/upload/effect inventories into the
provider preparation and receipt v2,
and uses the signed operation journal. Any missing capability produces a loud
WARN and retains the operator-directed checkpoint path; a configured but
failed preflight refuses instead of degrading.

Adoption probes all configured capabilities. Provider tokens and key
material never enter the registry or command line; only hashes and the
external key id are bound into a signed receipt. See
[docs/recovery-runtime.md](../docs/recovery-runtime.md) for the protocol and
automatic-profile boundary, and
[docs/checkpoint-bundle.md](../docs/checkpoint-bundle.md) for the checkpoint
contract, and [docs/code-release-runtime.md](../docs/code-release-runtime.md)
for the atomic code-release contract.
See [docs/upload-bundle.md](../docs/upload-bundle.md) for the upload/media
journal and provider contract.
See [docs/effect-bundle.md](../docs/effect-bundle.md) for the manifest grammar,
preflight isolation, runtime reconciliation, and inverse contract.

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

Pass `--envs-file=<path>` to explicitly trust and load the overlay at that
path instead of searching for `.duo-envs.json`. Auto-discovery refuses a
Git-tracked `.duo-envs.json`, because repository content cannot authorize a
privileged host provider; the explicit flag is an operator trust decision and
must never be populated from an untrusted repository or script.
`site.duo.json` discovery is unaffected by this flag — it's specifically an
override for the machine-local half of the registry.

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
materializing ordinary `wp duo` commands have no structural reason to carry
one, while the `duo init` workflow explicitly requires it. This project's
base sandbox images verifiably omit Git, so outside init the check degrades to an honest
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
