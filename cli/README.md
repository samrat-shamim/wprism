# wprism — the orchestrator CLI

`wprism` is the host-agnostic, multi-environment front end for the WPrism agent.
Git stays git — branching, merging, and history all happen on the site repo
exactly as before. `wprism` only adds two things on top of the per-environment
`wp wprism capture|plan|explain|apply` commands (see [agent/src/Command/Cli.php](../agent/src/Command/Cli.php)):

1. **Environment drivers** — one narrow workflow boundary with local,
   container, and SSH transports, plus a closed capability report so WPrism can
   refuse unsupported operations before it contacts the target.
2. **Cross-environment ergonomics** — `wprism envs`/`wprism doctor`/`wprism status`,
   which no single-environment `wp wprism …` call can give you.
3. **Truthful code-and-state sequencing** — compilation produces one
   content-addressed artifact whose `artifact_hash` binds the state revision
   and, when present, its separate code revision. `wprism deploy` materializes
   that code around the lifecycle window; `wprism promote` additionally takes a
   database checkpoint and converges the bound state revision under one
   target-database promotion lease.

`wprism` is dependency-free PHP 8+: no composer, no vendored packages, no
WordPress required on the machine that runs it. Requires only a `php`
binary and, per environment, whatever the transport itself needs (`ssh`,
`docker compose`, or a local `wp-cli`).

## Usage

```
wprism envs
wprism env materialize <env> --from <production-env> --branch <ref> [--create] [--ttl <seconds>] [--format=json]
wprism env reap <env> [--format=json]
wprism manifest-validate <adapter-library> [--manifest=<name>[,...]] [--pins=<name>[,...]|--all] [--site=<site-repo>] [--no-code] [--format=json]
wprism manifest-validate --emit-schema
wprism adapter list [--repo=<site-repo>] [--format=json]
wprism adapter inspect <name> [--repo=<site-repo>] [--format=json]
wprism adapter doctor [--repo=<site-repo>] [--format=json]
wprism adapter-observe <env> [--out=<local-file>|--format=json]
wprism doctor <env>
wprism driver-capabilities <env> [--operation=<workflow>] [--format=json]
wprism connect <env> --workspace=<path> --transport=ssh --host=<host> --wp-path=<path> --repo-path=<path> [--format=json]
wprism connect <env> --workspace=<path> --transport=docker --compose-file=<path> --wordpress-service=<name> --tooling=managed [--compose-env-file=<path>] [--profile=<name>] [--format=json]
wprism connect <env> --workspace=<path> --transport=docker --compose-file=<path> --wordpress-service=<name> --service=<wp-cli-service> --wp-path=<path> --repo-path=<path> [--mode=run|exec] [--compose-env-file=<path>] [--profile=<name>] [--format=json]
wprism onboard <env> [--git-url=<empty-url>] [init flags...] [--format=json]
wprism onboard <env> --handoff-only --git-url=<url> [--format=json]
wprism onboard <env> status --git-url=<same-url> --format=json
wprism adopt  <env>
wprism unadopt <env> --archive-to=<absolute-path> [--yes]
wprism init   <env> [--yes] [--allow-unmanaged-plugins] [--first-party=<root>/<slug>[,…]] [--offline] [--cache-dir=<path>] [--configure-database --database-service=<name>]
wprism code-classify <env> [--dry-run] [--first-party=<root>/<slug>[,…]] [--offline] [--cache-dir=<path>]
wprism code-resolve  <env> [--dry-run] [--offline] [--cache-dir=<path>]
wprism code-import <archive.zip> [--component=<slug>] [--root=plugins|themes] [--cache-dir=<path>] [--format=json]
wprism status <env> [--category=<ids>] [--action=<buckets>] [--entity=<kinds>] [--cursor=<token>] [--limit=<1..200>]
wprism assess <env> [--operation=<ops>] [--limit=<1..200>] [--cursor=<token>] [--format=json]
wprism contract <env> show|propose|accept|attest [--format=json]
wprism rehearse <env> --from <production-env> [--branch <ref>] [--create] [--ttl <seconds>] [--limit=<1..200>] [--format=json]
wprism rehearse <env> --reap [--format=json]
wprism preview create <env> --from <production-env> [rehearse flags...]
wprism preview remove <env> [--format=json]
wprism demo start [--scenario=core|woocommerce] [--name=<name>] [--source-port=<port>] [--target-port=<port>]
wprism demo review [--name=<name>] --accept-page-only
wprism demo status|capture|apply|refusal|stop [--name=<name>]
wprism stage-source <env> --from=<branch-or-tag> --operation=<id> [--format=json]
wprism authority-policy <env> status --format=json
wprism authority-policy <env> sync --policy=<file> --expected-current=absent|sha256:<hex> --format=json
wprism release <env> prepare --stage-receipt=<file> --expected-stage-receipt-sha256=<digest> [--format=json]
wprism release <env> execute --prepare=<file> --authorization=<file> --expected-authorization-sha256=<digest> --expected-subject-sha256=<digest> --expected-presentation-sha256=<digest> --expected-plan-digest=<digest> --expected-stage-receipt-sha256=<digest> --format=json
wprism release <env> status --prepare=<file> --expected-subject-sha256=<digest> --format=json
wprism release <env> --plan-only [--from=<ref>] [--profile=<p>] [--accept-weaker-recovery] [--with-deletes] [--limit=<1..200>] [--format=json]
wprism verify <env> [--plan=<digest>] [--limit=<1..200>] [--format=json]
wprism recover <env> [--list] [--restore=<checkpoint>] [--writers-excluded] [--operator-directed] [--limit=<1..200>] [--format=json]
wprism recover <env> prepare --restore=<checkpoint> --operation-id=<id> --format=json
wprism recover <env> execute --plan=<plan.json> --authorization=<signed-envelope.json> --format=json
wprism capabilities <env> [--format=json]
wprism capture <env> [--target-branch=<name>] [--scope-contract=<local-path>] [extra wp-cli flags...]
wprism lint    <env> [extra wp-cli flags...]
wprism plan    <env> [--scope-contract=<local-path>] [extra wp-cli flags...]
wprism explain <env> <bucket>:<entity-key> [--format=json] [planning flags...]
wprism apply   <env> [--scope-contract=<local-path>] [extra wp-cli flags...]
wprism deploy  <env> [--force-code-mismatch] [--force-code-drift]
wprism env-set <env> --name=<name> --stdin
wprism promote <env> [extra apply flags...]
wprism pending <env>
wprism classify <env> [--accept-proposals|--export-batch=<path>|--apply-batch=<path>]
wprism coverage <env> [--format=json]
wprism scope <env> --roots=<selectors> [--contract] [--format=json]
wprism refresh <production-env> --production-ref=<ref> [--scope-contract=<local-path>] [--field-diff [--format=json]]
wprism rebase <production-env> --production-ref=<ref> --new-branch=<name> [--scope-contract=<local-path>] [--strategy=manual|ours|theirs] [--resolve=<stable-id>=ours|theirs ...]
wprism rebase <production-env> --production-ref=<ref> --new-branch=<name> [--field-resolution=<local-path>|--interactive]
wprism rebase <production-env> --abort=<run-id>
wprism -h | --help
```

Run `wprism --help` for the full usage text (verbs, global flags, registry shape).
Environment names use the shell-safe grammar
`[A-Za-z0-9][A-Za-z0-9._-]{0,63}`; option-looking or control-bearing names
are rejected when the registry is loaded.

### First contact

`wprism demo start` owns a disposable source-checkout pair and prints the
complete review → capture → Git diff → authorization preview → evaluation apply
→ refusal → teardown loop. The default WordPress-core scenario installs no
extension, verifies the exact digest-pinned WordPress 7.1 image, and stops only
after the managed core capability set qualifies and a bounded machine
assessment exits ready. `demo review --accept-page-only` narrows the generated
contract to the page surface, removes the unused code-lifecycle placeholder,
and commits only `contract.json` plus `projection.json`; without that exact
confirmation it mutates nothing. The later apply runs the real plan-only release
command before raw evaluation apply, proves exact page convergence, and preserves
a target-only comment. A red assessment blocks this guided journey.
`--scenario=woocommerce` is the advanced adapter journey. Both use the same
pair budget and digest-pinned artifact resolver as the live test estate;
`demo stop` removes only the named demo's pair resources and repositories.

`wprism connect` does not mutate the WordPress application or its Compose
service. Its native target checks are raw reachability, installed WordPress, and
single-site topology. Explicit `--tooling=managed` also builds or reuses the
pinned helper image and creates WPrism-owned private overlay/repository resources
before creating a dedicated local Git root containing the shared adoption seed
and a mode-`0600`, ignored `.wprism-envs.json`; topology inspection boots
WordPress, so site startup code may have its own effects. `wprism onboard` then composes the existing `adopt`, `assess`,
and `init` gates in that order. `--git-url` must be empty and accessible with
Git credentials from both controller and target; WPrism preflights both before
target mutation, publishes the target's current branch, and replaces only
connect's byte-verified local seed with that exact checkout. If init completed
without a URL, `--handoff-only --git-url=<url>` resumes just that handoff.

Automation uses the same public verbs without scraping their human output.
`connect ... --format=json` emits one canonical
`wprism-connection-receipt/v1` document that binds the inspected workspace and
machine-local environment configuration while retaining the honest startup-code
boundary. `onboard ... --git-url=<url> --format=json` emits one canonical
`wprism-onboarding-handoff/v1` document. It binds the stable target operation
identity; the target, controller, and remote Git branch/commit/tree; the
machine-local environment generation; assessment and application-contract
evidence; authority-policy enrollment; and one closed next action. Progress
stays off stdout in machine mode. After a lost response, reconcile without
repeating adoption or publication:

```sh
wprism onboard production status --git-url=<same-url> --format=json
```

Status returns byte-identical evidence while those bound inputs are unchanged.
It never creates target identity, changes Git refs, or substitutes an
application-contract proposal for reviewed authority.

`wprism preview create|remove` is the first-contact spelling of the established
`wprism rehearse <env> ...|--reap` contract. It is a strict argument translation,
so provider capabilities, containment disclosure, receipts, and refusal
semantics remain the rehearsal implementation's.

- **`wprism envs`** — lists every environment in the merged registry with a
  one-line transport summary. Exit 0 if the registry has at least one
  environment (even if some environments' configs are individually invalid —
  those print as an inline `ERROR: …` row instead of failing the whole
  listing), exit 1 if no `envs` were found anywhere.

- **`wprism manifest-validate <adapter-library>`** — the one verb here that takes no
  environment, because it needs none: it runs the engine's real manifest
  validators (`agent/src/Policy/Policy.php`'s load-time battery) over an
  explicitly selected adapter library with no WordPress, no database, and no
  transport. Each manifest is loaded on its own, then the requested pin set is
  co-loaded so the
  cross-manifest guards run too; engine refusals are surfaced verbatim with
  their own coordinates, a per-manifest row carrying that manifest's file path
  and the pin-set row carrying the paths of everything co-loaded.
  `--manifest=` narrows what is checked individually, `--pins=`/`--all` choose
  the co-loaded set, `--format=json` emits the report as
  `wprism-manifest-validation/v1`, and `--emit-schema` prints the grammar document
  (`wprism-manifest-grammar/v2`) read out of the engine's own closed vocabularies,
  the `spec_version` window it measures by probing the shipped refusal, and the
  signer's closed top-level key partition.
  `--site=<site-repo>` is optional but not cosmetic: two of those guards read
  `site.wprism.json`'s policy half as input (a site-declared table extends the
  ref/token/ledger kind vocabulary; a site `policy.options` rule resolves an
  option two manifests declare differently), so without it a manifest that is
  valid on its real site can be refused here — and such a refusal is annotated
  as possibly site-resolvable rather than rewritten.
  The normal argument is a source tree containing `adapter-packages/` and
  `platform/adapter-library/`; an explicitly named flat directory is only a
  legacy validation/import surface. Point it only at a library you trust as
  much as the agent's own:
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

- **`wprism adapter-observe <env>`** — asks the configured target exactly once
  for a closed, canonical `wprism-adapter-observation/v1` proposal-evidence
  projection. The host supplies only that environment's configured
  `repo_path`; there is no host-local `--repo` escape. The response is strict
  JSON with a recomputed observation hash, value redaction, and no target IDs,
  titles, paths, messages, SQL, credentials, or authored values. `--out=`
  retains the already-validated canonical document only at a new local path:
  it refuses an existing file, symlink, FIFO, or other directory entry rather
  than replacing evidence.
  The nested `catalog` is a deliberately lossy projection of the target's
  `wprism-adapter-sources/v2` survey, not a claim to preserve the full
  `wprism-adapter-catalog/v2` contract. This evidence is never authoritative
  AdapterDraft input and alters no certification and no capability claim.
  Normal plugin/provider registration and capability negotiation remain
  enabled so installed adapters are observable; third-party callbacks may have
  side effects before or during collection. WPrism invokes no provider action and
  performs no explicit mutation after observer entry, while the report itself
  defers table semantics, apply/rollback, lifecycle changes, publication, and
  certification.

- **`wprism adapter list|inspect|doctor`** — the installed-adapter catalog, and
  the other verb here that needs no environment. It reports what is installed
  across the two adapter sources a WordPress-free process can reach — the
  selected `AdapterLibrary` (the installed agent's embedded library in
  production) and, with `--repo=<site-repo>`, that
  repository's own `adapters/` overlay — where each adapter came from, the
  executable authority its own declarations reach, and what is wrong with any
  of it. No WordPress, no database, no transport.
  The engine has a THIRD source: one `wprism-adapter.json` at the root of each
  ACTIVE plugin that bundles one. It lives in `WP_PLUGIN_DIR`, so only the
  target can see it — every report here carries a `sources` block marking each
  of the three scanned or not scanned and why, and `wp wprism adapter-survey
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
  `inspect <name>` adds the reviewed disposition entry, the capability claim
  that disposition projects (supported versions, operations, surfaces, explicit
  unsupported boundaries, the authored evidence citation), the providers the
  manifest requires with the capabilities each must advertise, and the
  verification facts that already exist — the cited bundle schema, the claim's
  `plugin_execution.status`, and the test ids the citation names. There is no
  verification score, no per-test verdict (nothing here runs those tests), and
  no pin digest: adapter identity is resolved against a loaded site policy, so
  `wprism adapter certify <repo> --name=<n> --pin` is what prints and writes the
  exact `{name,source,digest}` object.
  `doctor` adds this repository's readiness blockers (with `--repo`) and every
  installed file the engine refuses to load — a shadowed adapter, an ambiguous
  identity, a case-confusable name, a symlink, a nested or near-miss `.json`,
  a reserved name — each as a ROW carrying the engine's own message, a stable
  code, and its remediation. That is the point of the verb: `wprism adapter
  doctor` is what you can still run when the repository is in one of those
  states, because every other command refuses first. Every refusal row also
  names the source it is about and whether it refused that whole source or one
  adapter, and a separate `not_installed` block lists adapters that are on the
  machine and lost to a higher-precedence definition (sources rank
  `shipped > site > plugin`) — those print on every run and deliberately never
  change the exit code, because a correctly resolved shadow is precedence
  working rather than a fault. The report format is
  `wprism-adapter-catalog/v2`.
  Exit 0 healthy, 1 anything surfaced, 2 usage/IO. Every run, passing or
  failing, ends with a `deferred` list: what needs a live target (plugin/theme
  state, provider negotiation, certification against one environment), the
  manifest-shipped PHP it names but deliberately never loads, and the
  cross-manifest guards that belong to a pin set rather than to one adapter.
  It never claims a live verdict.

- **`wprism doctor <env>`** — gated checks, each skipped (reported as a
  failure) once an earlier one fails, since a broken transport makes every
  later check meaningless noise:
  1. transport reachable (`echo` round-trips through the transport)
  2. WordPress installed (`wp core is-installed`)
  3. the wprism agent is present (`wp eval` checks `class_exists('\WPrism\Capture')`)
  4. `repo_path` exists and contains `site.wprism.json`
  5. `.wprism-env-values.json` (issue #3232's target-local intended-value
     authority — see "Env-bound value provisioning" below) is not
     git-tracked. **Advisory, not blocking, when this environment has no
     `git` binary to check with** — verified live that this project's own
     sandbox images (`wordpress:cli-php8.3`) genuinely don't ship one, so
     this degrades to "could not verify" (a WARN naming exactly that)
     rather than a silent, wrong PASS; when git *is* available, a tracked
     file is a real, blocking failure, same severity as checks 1-4.
  6. `DISALLOW_FILE_MODS` is set (advisory — issue #3231, closes the wp-admin
     file-mod UI that can silently drift installed code out from under git)
  7. installed PHP version is inside `docs/compatibility-baseline.json`'s
     declared range AND its MAJOR.MINOR is one of the exercised series that
     baseline names in `php.verified` (issue #3222)
  8. installed database engine is a key of the same baseline's
     `database.engines` map, and the installed version is inside THAT
     engine's own range — never another engine's
  9. installed WordPress core version is inside the same baseline's range AND
     its MAJOR.MINOR is one of the exercised series that baseline names in
     `verified` (a minor line inside the range that nobody ran still fails)

  Exit 0 only if every check above except the ones that were actually
  advisory on this particular run (6 whenever `DISALLOW_FILE_MODS` is
  genuinely unset; 5 only in the no-git case)
  passes.

- **`wprism driver-capabilities <env> [--operation=<workflow>]`
  `[--format=json]`** — computes a target-free, versioned
  `wprism-environment-driver-capabilities/v1` preflight. The same canonical
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
  Local and Docker declare initial delivery only when the exact bootstrap
  opt-in came from the untracked machine-local registry; this target-free
  declaration is not a target-safety attestation, and `adopt` still runs the
  separate eligibility report. Docker additionally proves a local daemon, a
  running application container, exact shared durable WordPress storage, and
  durable repository storage. None of the built-ins claims host
  provisioning, destroy/TTL, media snapshot, maintenance-mode, URL mutation,
  or driver-owned operation receipts. Those stay visibly unsupported until a
  driver implements them; provider provisioning remains an optional driver
  extension rather than engine behavior.

- **`wprism env materialize <env> --from <production-env> --branch <ref>`** —
  creates the default short-lived branch environment without collapsing its
  three separate contracts. `Refresh` first rebases the clean, currently
  checked-out branch against live production semantic truth in an isolated
  worktree. A separately configured host provider freezes the production
  snapshot session while that semantic export is produced, creates one
  coherent opaque DB/media snapshot set, and serves an immutable readback that
  binds the physical hashes to that exact semantic snapshot. The provider then
  attaches to the target (the default) or explicitly creates it (`--create`),
  restores the physical baseline, materializes the candidate repository
  commit, and restores the provider-owned target URL. When the target transport
  explicitly supports adoption (SSH, or an opted-in local transport), the
  command surface then installs this controller's exact WPrism distribution
  through the existing atomic adoption transaction. The provider receives no
  WPrism source path or agent bytes, while the environment engine journals only
  the target-bound installed-version and immutable-distribution receipt. The
  distribution pin covers every assembled `agent/` and `recovery/` source byte,
  is read back from the installed target before the adoption commit barrier,
  and separately proves the production-loaded top-level MU loader equals the
  canonical `agent/wprism-loader.php` byte mapping. The pin is part of
  materialization intent, so changed controller bytes cannot
  resume an earlier bootstrap. A transport without adoption
  authority must already carry a compatible agent. Finally the ordinary
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
  identity- and lease-fenced `wprism env reap`. Retries reuse the immutable
  operation id, skip every journaled phase, and reconcile an uncertain phase
  with the same inputs and ownership tuple, so provider actions must be
  idempotent for that id. A crash after target adoption but before its journal
  receipt safely re-enters the same atomic adoption transaction; a journaled
  bootstrap is never replayed. Convergence — and any promotion receipt reconciled
  from a lost controller response — is judged only from a *complete* `wp wprism
  plan --format=json` envelope: a valid JSON document with a missing or
  non-list required bucket is refused by name, never counted as clean.

- **`wprism env reap <env>`** — reads the latest immutable machine-local
  materialization journal, re-inspects the provider resource, and performs a
  provider-side compare-and-reap against its exact environment, resource,
  ownership lease, mutation fence, and optional TTL generation/id. Created
  resources require `environment.destroy`; attached resources require
  `environment.detach` and are never destroyed. A reused name, changed
  lease/generation, tampered
  receipt, or missing explicit capability refuses before the destructive call.
  Repeated reap returns the existing absence proof without another provider
  call. A changed TTL or mutation lease refuses before detach or destroy.

- **`wprism adopt <env>`** — installs or updates this checkout's complete WPrism
  agent with its assembled embedded adapter library on a pre-existing SSH
  target, or performs the initial install on an explicitly opted-in
  machine-local target, creating a minimal core-only `site.wprism.json` only when
  that file is absent, verifies
  the exact installed agent/recovery byte distribution and live MU loader, agent version, policy load, rollback authority, and
  blocking `wprism doctor` rows before committing the filesystem transaction.
  Existing site policy is retained. The target needs
  no Git for adoption itself. Adoption embeds the selected source packages and
  platform library in the installed agent, so no process environment chooses
  its runtime library. The separate `wprism init`
  workflow does require target Git. Local delivery is privileged: the exact
  `bootstrap` object below must come from the untracked machine-local overlay,
  static capability reporting remains target-free, and `adopt` then obtains a
  separate read-only eligibility proof before allocating or changing target
  paths. Local bootstrap refuses an already-installed WPrism control plane;
  installed-target updates use the existing update path. Authorized local
  Docker delivery is also initial-only and uses the same transaction. See the operator procedure and
  safety/update contract in [docs/adoption.md](../docs/adoption.md).

- **`wprism unadopt <env> --archive-to=<absolute-path> [--yes]`** — performs the
  supported client-offboarding transaction. It binds a read-only plan to the
  exact agent, MU loader, and target-local recovery/control tree, copies and
  verifies those bytes in the operator-selected archive, stages them out,
  proves WordPress remains installed with WPrism absent, and only then commits.
  Repository policy, code, media, state, Git data, attributes, and durable
  revocations remain in place and are named in `receipt.json`. The archive is
  mandatory and never silently deleted; stale ownership or a failed post-move
  proof restores the live control plane.

- **`wprism init <env> [--yes]`** — asks the reachable target agent for a
  deterministic, read-only proposal covering platform facts, active code,
  certified adapters, authored scope, media availability, and value-redacted
  risk surfaces. It refuses unsupported boundaries before mutation. On
  confirmation it rechecks the proposal digest under the target's init and
  publication locks, verifies or creates a target-owned Git worktree, and
  publishes separate code and canonical state/media baselines. The final
  status proof is limited to selected managed scope. Init does not install
  WordPress or deliver the agent; SSH and explicitly authorized local targets
  use `wprism adopt` first, while Docker targets must already expose the agent
  through their control plane.

  Baseline confirmation completes before that final status proof. If the proof
  reports readiness blockers, the baseline is committed: do not rerun init.
  Resolve every reported blocker; for each `env_missing` row, supply its
  binding with `wprism env-set <env> --name=<name> --stdin`. Then verify
  `wprism status <env>` and use `wprism onboard <env> --handoff-only
  --git-url=<same-url>` when Git publication is pending.

  A local Docker baseline blocked because the WordPress database account lacks
  direct global `PROCESS` can be resumed explicitly with `wprism init <env>
  --configure-database --database-service=<compose-service>`; the same flags
  may be passed to a fresh `wprism onboard`. This opt-in proves that the
  selected running official MySQL/MariaDB container is the exact server
  WordPress uses and grants only `PROCESS ON *.*` to the exact authenticated
  WordPress account. `PROCESS` is server-wide; no user, password, schema/table
  privilege, `ALL`, or `GRANT OPTION` changes. Ordinary `--yes` never enables
  setup. MariaDB setup additionally requires immutable `server_uid` identity
  (11.1.6, 11.2.5, 11.4.3, 11.5.2, or 11.6.1+); this narrower setup prerequisite
  does not narrow ordinary read-only onboarding's platform range. Other
  database safety blockers remain refusals.

  Init also **classifies the code half**, and Git never carries third-party
  code. It asks the target for each active component's `{root, component,
  version, tree_sha256, bytes, files}` — the target reaches no registry, ever
  — then, on THIS host, LOCKS each component whose installed tree is
  hash-identical to its published wp.org component archive, or each bundled
  theme whose tree is hash-identical to its exact nested path in the target's
  verified WordPress core release archive (both resolved into a
  content-addressed cache and unpacked), or to an archive imported with
  `wprism code-import`; records each component named by `--first-party=<root>/<slug>`
  as the site's own code, which Git carries by that declaration; and BLOCKS the
  proposal on anything else (`code_component_unsourced`, naming both remedies).
  There is no "vendor it anyway": `--code=full` no longer exists and is refused
  by name. The classification is sent back inside the proposal, so it rides in
  the digest exactly as `--allow-unmanaged-plugins` does and a stale
  `--confirm` cannot apply a classification nobody read. On confirmation the
  agent publishes `code/wprism-code.lock.json` (`wprism-code-lock/v2`: the locked
  `components` and the `first_party` declarations) and the root-anchored
  `.gitignore` lines for the locked trees. `--offline` contacts no registry, so
  a wp.org component or core-bundled theme locks only from the host cache;
  `--cache-dir=<path>`
  overrides the default cache (`$XDG_CACHE_HOME/wprism/code-artifacts`, else
  `~/.cache/wprism/code-artifacts`), which also holds the imported-archive store.

- **`wprism code-import <archive.zip> [--component=<slug>] [--root=plugins|themes] [--cache-dir=<path>] [--format=json]`** —
  puts a release archive you hold into THIS host's code-artifact cache, so a
  component with no wp.org release (a premium plugin, a private theme, a vendor
  build) can be LOCKED instead of carried in Git. No `<env>`: the cache is the
  host's, and the import has to be possible before the `wprism init` that
  classifies the component. It reads exactly one local file, unpacks it to
  prove it is one component directory, and prints the `archive_sha256` the
  lock will record and the `tree_sha256` an installed component must hash to;
  it records nothing about where the archive came from. Pass `--component`
  when the archive's top-level directory is not named after the component.
  Importing the same archive again is idempotent. WPrism never downloads from a
  vendor: move the archive to every host that resolves, with this command.

- **`wprism code-classify <env> [--dry-run] [--first-party=<root>/<slug>[,…]] [--offline] [--cache-dir=<path>]`** —
  (re)declares an ALREADY-INITIALIZED repository's code half, format 1 or 2.
  It runs from inside the site repository, like `wprism assess` and
  `wprism contract`, and writes only into that local checkout:
  `code/wprism-code.lock.json`, the root-anchored `.gitignore` lines, `code`
  format 2 in `site.wprism.json`, and `git rm --cached` for each NEWLY locked
  tree. `<env>` is used to ask the target for its own `wp wprism code-inventory`
  and exact WordPress core version, so a checkout that disagrees with the
  target it deploys to is refused before anything is untracked and a bundled
  theme can be compared with the right core archive. A format-2 repository is
  re-classified rather than refused — run it again after importing an archive,
  to add a declaration, or to upgrade a v1 lock — and the declarations it
  already holds carry forward.

  The bytes never leave the working tree, so **the next compile produces the
  identical `code_revision`** — asserted by the command itself before it
  untracks anything, not assumed. No re-pin, no deploy, nothing fleet-visible.
  It refuses a repository with uncommitted changes under `code/`, one whose
  `site.wprism.json` is not canonical, one whose lock names a component that is
  not on disk (run `wprism code-resolve` first), and — the invariant — any run in
  which a component is UNSOURCED: neither locked nor declared first-party.
  A fresh clone afterwards needs `wprism code-resolve` below; until it runs,
  compilation refuses by name with `code_component_unresolved`.

- **`wprism code-resolve <env> [--dry-run] [--offline] [--cache-dir=<path>]`** —
  materializes every component `code/wprism-code.lock.json` declares into
  `code/wp-content`, on THIS host. For each entry it reads the same
  content-addressed cache `wprism init` uses and fetches **only on a miss** (an
  `imported-archive` entry is read from that cache's imported store, where
  `wprism code-import` put it, and refuses `code_resolve_archive_missing` on a
  host where it was never imported), verifies `origin.archive_sha256` before unpacking and
  `tree_sha256` after, unpacks into a staging directory under `.wprism/`, and
  renames only a verified tree into place — so a component is never
  half-written. There is no latest-fallback and nothing is skipped on a miss.

  A component already present at its locked digest is reported unchanged and
  is not rewritten. A component present at any OTHER digest **refuses**
  (`code_resolve_component_drifted`) rather than being overwritten: its tree is
  `.gitignore`d, so those bytes exist in exactly one place. The other refusals
  — `code_resolve_cache_corrupt` (refuse, do not re-fetch, the changed byte is
  evidence), `code_resolve_archive_digest_mismatch` (the partial download is
  deleted and nothing is cached), `code_resolve_tree_digest_mismatch`,
  `code_resolve_offline_miss` — each name their own remedy.

  **Resolution is always host work**, because the target never reaches a
  registry. On `local` the environment's `repo_path` IS the host path; on
  `docker` the host side of the bind mount is derived from the environment's
  own compose service. On `ssh` the host resolves into a throwaway staging
  worktree and **pushes** (issue #3514): one tar, unpacked into a staging
  directory under `<repo_path>/.wprism/code-push/`, verified there against the
  lock's `tree_sha256` through the target's own `wp wprism code-inventory` before
  anything is renamed into `code/wp-content`, then re-verified afterwards. A
  component the target holds at a different digest refuses
  `code_resolve_component_drifted` before anything is transferred; a failed
  transfer refuses `code_resolve_push_failed` with the target unchanged. A
  transport with neither a writable host path nor a push mechanism still
  refuses `code_resolve_transport_unsupported`.

  `wprism deploy` and `wprism promote` run this same resolver automatically as
  `<verb> phase: code-resolve`, before `compile` and outside every promotion
  lease. The phase is silent for a repository with no lock. On `ssh` with a
  lock present it reads the target's own `wp wprism code-inventory` first,
  transfers nothing for the components already at their declared digest, and
  pushes only the missing ones — refusing before compile if any is drifted.
  See [docs/guides/code-updates.md](../docs/guides/code-updates.md).

- **`wprism status <env>`** — runs `wp wprism plan --repo=<repo_path>
  --format=json` (note: `--format=json`, not `--json` — wp-cli's dispatcher
  rewrites a bare `--json` into `--format=json` before the command ever sees
  `$assoc['json']`; see the comments in `agent/src/Command/Cli.php`) and renders a
  human summary: counts per plan bucket (create/update/adopt/unchanged/
  drift/conflict/collision/delete/code_mismatch/code_drift/incomplete_apply/
  incomplete_lifecycle/regen_pending/env_missing/adapter_dispositions), drift paths, blocked-delete
  reasons, incomplete_lifecycle recovery receipts (an unresolved pre-hook
  lifecycle boundary requiring restoration of the exact database checkpoint),
  code_mismatch and code_drift findings (the latter is an installed code
  version/provenance change after WPrism's last trusted observation),
  regen_pending entries (a derived table with a hard per-entity
  availability dependency — issue #3234, e.g. TEC's tec_occurrences — whose
  post-apply verification failed and hasn't yet resolved), env_missing
  entries (a manifest-declared `class: "env"` option or canonical post-password binding unset on this
  environment — issue #3232, see "Env-bound value provisioning" below), and
  any plan-level warnings.

  Each required env entry includes its exact host-side
  `wprism env-set <this-env> --name=<this-name> --stdin` command, including the
  operator-selected `--envs-file` binding when one was supplied. Target-form
  `wp wprism env-set` advice from the agent is suppressed on this host-rendered
  surface so one diagnosis never asks the operator to choose a transport.

  Entity rows that carry an authored WordPress display name — a post's
  `title` front matter, a term's or menu's `name` — expose it as the row's
  `title` key in plan JSON (issue #3345). `wp wprism plan`'s own human output
  prints it in single quotes after the repository path on every itemized
  row. `wprism status` itemizes only rows that demand a decision — drift,
  conflict, collision, blocked/conflicted deletes — so the name appears
  here exactly on those rows; a clean create/update batch still renders as
  counts alone on the summary line. Rows without an authored name
  (options, sidebars, typed tables, tombstones) render exactly as before;
  the name is never guessed or derived.

  New agents also add an optional `category_summary` projection with format
  `wprism-plan-category-summary/v1`. It keeps the detailed plan untouched and
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

  Current agents additionally emit `affected_surfaces`, a sorted, unique,
  value-free list in the assess/contract surface vocabulary. It comes from
  the same exact changed/delete/retry selection that chooses Apply's provider
  actions. Release and rehearsal use it to keep a product edit scoped to
  `post_type:product` rather than every declared post type. If an older agent,
  or an entity kind with no exact projection yet, omits the field, the host
  retains the documented entity-kind superset. A present malformed, unknown,
  duplicate or unsorted id refuses; it never earns a narrower fallback.

  An explicit `--category=<csv>`, `--action=<csv>`, `--entity=<csv>`, emitted
  `--cursor=<token>`, or
  canonical `--limit=<1..200>` on `wprism status` (or forwarded through
  `wprism plan`) requests the additive `wprism-plan-view/v2` display projection.
  It leaves every detailed plan bucket in the JSON envelope unchanged, carries
  `authoritative: false`, and contains only closed bucket/entity/category
  facets, a safety bit, and an opaque hashed explain selector. It has no raw
  title, path, value, secret, target id, source position, text search, or
  plugin-specific engine filter. The selector resolves one UUID within the
  full bucket, while presentation order is fixed action rank then UUID bytes;
  authoritative source order remains untouched. CSV values are exact closed
  tokens (OR within each dimension, AND across dimensions), deduped into
  vocabulary order. An explicit view defaults to and caps ordinary rows at
  200 and emits an opaque next cursor while matching rows remain. Each cursor
  binds the complete plan identity and filter set; a changed plan refuses it
  as `plan_view_cursor_stale`, so a dashboard can enumerate every matching
  ordinary row exactly once from one stable observation sequence. Safety rows
  (drift, conflict, collision, delete conflict, and blocked
  deletes) and global diagnostics bypass filters and the cap. A category view
  additionally requires the same-snapshot category summary. Status forwards
  one normalized request in its single full-plan call and fails closed with
  `plan_view_unavailable` if the requested projection is absent, malformed, or
  cannot be tied to that complete envelope; it never makes a second plan call
  or guesses a filtered result. No-option JSON and normal behavior remain
  compatible; filtered direct-plan rows and host status plan-row labels
  normalize C0/DEL controls to one line. Direct machine consumers that cannot
  ingest the complete detailed plan add `--view-only --format=json`; this emits
  only the bounded page envelope, including full-plan readiness/count evidence
  and all safety rows. `--view-only` without a view argument and JSON format
  refuses. V2 plan-view flags and
  `--scope-contract` are deliberately mutually exclusive: a scoped plan is a
  different closed projection already bounded to its selected contract, so
  combining them typed-refuses as `plan_view_unavailable` rather than silently
  dropping the requested view or pretending it is a complete detailed plan.

  Exit non-zero ("not safe to promote") if the plan contains any
  `conflict`, `collision`, `code_mismatch`, or `code_drift` entry, any
  blocked delete, any ordinary state drift, a retained `incomplete_apply`
  marker, an `incomplete_lifecycle` receipt, a `regen_pending` entry, or a
  **required** `env_missing` entry, or a pinned manifest whose external
  disposition is `experimental` or `excluded`. An `incomplete_lifecycle` receipt requires
  restoring the exact pre-lifecycle database checkpoint; force flags cannot
  bypass it.
  `wprism apply` refuses code_drift unless explicitly passed
  `--force-code-drift`; ordinary state drift instead refuses before mutation
  and requires capture/reconciliation (except for the separately checkpointed
  scoped-promotion authority). A regen_pending marker makes the *next* apply
  retry rather than refusing at preparation. Env_missing has two executable
  cases. Ordinary environment options are never captured or applied, so their
  rows do not make apply refuse. A required protected-post
  `post_password:<uuid>` binding is different: apply materializes that
  target-local value into `post_password` and refuses transactionally when it
  is absent. Status reports either required case as not clean because it
  answers "safe to promote?", not just "will apply refuse?" — capture first
  for ordinary drift, retry for regen_pending (automatic on the next `wprism
  apply`), and use `wprism env-set` for env_missing. An *optional*
  (`required: false`) env_missing entry is still listed for visibility but
  never flips this by itself — it's plugin-internal bookkeeping the plugin
  populates on its own.
  Also non-zero if the underlying `wp wprism plan` call itself failed or
  returned unparseable JSON. Plain warnings are rendered but never flip this
  by themselves — see the decision-matrix comment in
  `cli/src/Plan/PlanSummary.php::render()`.

  `code_revision_stale` is stricter than a lifecycle compatibility finding:
  the current artifact's code payload has not completed the host
  `wprism deploy <env>` stage → lifecycle → finalize sequence. It is an
  unconditional code-before-state ordering gate. Neither
  `--force-code-mismatch` nor `--force-code-drift` permits `wprism apply` to
  cross it.

  `wprism status` parses and reformats; it does not print the raw JSON. Use
  `wprism plan <env> --format=json` for that.

- **`wprism assess <env> [--operation=<ops>] [--limit=<1..200>] [--cursor=<token>] [--format=json]`**
  — the decision-first, READ-ONLY assessment. One run composes `Doctor`, the
  adoption and initialization probes, `wp wprism assess-inventory`, `wp wprism
  capabilities` once per distinct capability-report operation, and the host adapter
  catalog, and emits one row per WordPress-language surface carrying its state
  class, handling, technical readiness, certification provenance, effect
  containment and effect recovery semantics, plus the unknown/unclassified
  queue counted and named and the smallest safe next action per gap from a
  closed set. Surface rows are derived from the target's policy surface groups,
  the capability claim's own surfaces and the contract's `surface_labels` map —
  never from a plugin name. Containment reads `unknown — not enforced in this
  profile` wherever it is not structurally prevented, and nothing here is a
  capability claim. It writes nothing to the target; locally it writes
  `.wprism/contract/<env>/proposed.json` and regenerates
  `.wprism/contract/projection.json` when a contract has already been accepted.
  The proposal is per environment and the contract and projection are per site,
  so assessing one environment never overwrites another's review in flight. `--operation` narrows the
  projected product operations (`capture`, `merge`, `release`, `verify`,
  `delete`, `recover`; default all six). A bare `--format=json` emits the
  complete contract-bound `wprism-assess-report/v1`; combining it with
  `--limit` or an emitted `--cursor` pages the non-authoritative
  `wprism-assess-view/v1` while retaining exact full-report counts/readiness.
  `--limit` continues to bound every human listing. Exit 0 means every requested projection is Ready/Ready with
  conditions, 3 is a complete assessment with red readiness, and 1 means the
  assessment itself refused.
  See [docs/guides/assess.md](../docs/guides/assess.md).

- **`wprism contract <env> show|propose|accept|attest [--format=json]`** — the per-site
  application contract as one reviewed object under `.wprism/contract/`.
  `propose` regenerates `.wprism/contract/<env>/proposed.json` from a fresh
  assessment; a proposal is never authority, and its generated external-effect
  entry is deliberately
  unreviewed, so accepting it unread is refused by the schema rather than by
  advice. `show` renders the accepted contract and its generated projection
  from disk and contacts nothing — they are committed review artifacts.
  `accept` re-runs the assessment, refuses a proposal generated for another
  environment (`contract_proposal_environment_mismatch`) and a stale one
  (`contract_proposal_stale`) rather than reconciling either, writes
  `contract.json` + `projection.json` canonically under
  compare-and-swap, and stages them; it never commits, because the commit is
  the reviewer's signature. `accept` writes `attestation.state: unsigned` and
  always will.
  `attest --secret-key-file=<path> --principal=<who> --policy-version=<v>
  [--expires=<ISO8601>] [--key-id=<id>] [--reason=<text>]` is the only verb
  that writes `signed`: it signs the already-accepted contract under an Ed25519
  key registered in this repository's own `.wprism/contract/authorities.json`,
  contacts nothing, verifies its own output before it writes, and force-stages
  the contract and the trust root. That trust root ships with no key, so on an
  untouched site attest refuses `contract_attestation_unsigned_anchor` before
  it reads a byte — the mechanism ships inert, and the honesty line every
  assessment prints stays literally true until an organization provisions one.
  A signed contract is re-verified on every read, so an edited byte refuses at
  all four consumers (`contract_attestation_signature_invalid`) instead of
  quietly reading as unsigned; an expired attestation
  (`contract_attestation_expired`) and one bound to a superseded agent
  capability boundary (`contract_attestation_platform_moved`) refuse by their
  own names, and the remedy for both is to attest again.

- **`wprism rehearse <env> --from <production-env> [--branch <ref>] [--create]
  [--ttl <seconds>]`** — `wprism env materialize` plus a preview: the same option
  grammar, the same machine-local `.wprism-envs.json` provider, the same
  capability negotiation, the same journal, the same exact
  resource/lease/ownership compare. A missing provider capability is a refusal
  naming that capability id; nothing is emulated. `--branch` defaults to the
  branch this working tree is on. Fresh SSH previews receive the exact pinned
  WPrism agent through the command-layer adoption seam after provider-owned
  repository placement and before compile; that delivery is not a provider
  action. After convergence it prints what a release
  would touch — the plan's own value-free category numbers and the assessed
  surface rows restricted to that scope. EVERY run states the containment
  requirement first, before provider contact, and materialization refuses to
  restore production-derived bytes without an exact target/resource/lease/
  fence-bound `environment.containment.verify` receipt. Only then does it print
  `containment: sandboxed` and the receipt. The ordinary local reference pair
  withholds that capability; the opt-in standalone `contained_preview` mode is
  the bundled development example. A containment receipt permits evidence
  gathering but does not itself authorize an `Experimental` or `Uncertified`
  capability. With `--format=json`, stdout is exactly one canonical
  `wprism-rehearsal-preview/v1` document; the nested materialization receipt is
  bound into its containment evidence instead of being emitted as a second
  document.
  `wprism rehearse <env> --reap` is `env reap` with the same compare-and-reap.

- **`wprism release <env> --plan-only [--from=<ref>] [--profile=<p>]
  [--accept-weaker-recovery] [--with-deletes]`** — the retained read-only
  authorization-plan preview. It writes neither target nor local repository
  bytes, and `--from` is only a binding assertion. Every invocation without
  `--plan-only`, including interactive and `--yes`, refuses
  `release_external_authorization_required` before planning or promote.
  Production mutation is available only through the signed stage/prepare/
  execute seam below. Exit 0 read-only plan, 1 refusal/failure, 2 usage.

- **`wprism stage-source` / `wprism release <env> prepare|execute|status`** — the
  externally authorized control-plane form of the same release. `stage-source`
  retains the exact advertised commit under target-private Git state without
  moving canonical `HEAD`, index or worktree, resolves any locked third-party
  code into that inert checkout from the controller side, and returns one
  durable receipt. Every retry and prepare re-proves that the staged code still
  matches the committed lock; the production target never gains registry
  access.
  `prepare` reads that inert checkout, current target facts and the already
  enrolled target-authoritative policy into one canonical subject without writing target or local
  repository bytes. An external Ed25519 actor signs its exact presentation and
  complete subject. `status` projects the target-private election, consumption
  and completion records for that exact subject as canonical
  `wprism-release-operation-status/v1`. Its monotonic sequence is bounded to
  four states and it never resumes mutation. `execute` requires explicit digests for the prepare,
  authorization, plan and stage; it refuses any plan, target-HEAD or source
  drift before one-time target-side consumption, then materializes the staged
  commit and composes promote. Repeating the exact execute command remains the
  exact replay: a complete target record returns its stored outcome even after
  authorization expiry, while elected/consumed-without-completion status exits
  non-zero and requires reconciliation without retrying mutation. The full signer wire, command sequence and crash semantics
  are in [docs/guides/release.md](../docs/guides/release.md#control-plane-release-stage-prepare-sign-execute).

- **`wprism authority-policy <env> status|sync`** — the explicit target-control
  policy boundary used by signed release and recovery. `status --format=json`
  reads target identity + policy digest and creates no byte when enrollment is
  absent. `sync` requires one canonical `wprism-operation-authorities/v1` file
  and an exact expected current identity (`absent` only for first enrollment),
  then fsyncs and atomically publishes it under the target's private Git
  control directory. Exact replay is idempotent; concurrent change refuses.
  Revocation is a reviewed `status: revoked` policy followed by this explicit
  CAS sync. Prepare and execute never silently install policy.

- **`wprism verify <env> [--plan=<digest>] [--limit=<1..200>] [--format=json]`** —
  post-release verification in two independent parts, both required for a pass:
  a fresh read-only convergence re-read of the target, and HTTP probes of the
  journeys the contract declares, each with its expected status and expected
  substring. An undeclared journey is never silently skipped — the report says
  `journeys: 0 declared` and, for every surface the scope touched with no
  journey declared, that verification is byte-level only for it. `--plan` binds
  the report to one frozen authorization plan and refuses if the contract has
  moved since. Exit 0 pass, 1 fail or refusal.

- **`wprism recover <env> [--list] [--restore=<checkpoint>] [--writers-excluded]
  [--operator-directed]`** — the operator verb over the recovery runtime; it
  replaces typing `recovery/rollback-control.php` by hand. `--list` prints the
  checkpoint catalog (receipt id, state, kind, generation, owner, covered
  inventory, age) with the disclosures that bound it — the rollback authority
  holds one generation at a time, so it is the active receipt and not a
  history. `--restore` performs the profile's own rollback, or drives the four
  ordered operator-directed steps (abort → begin → isolated import → final
  abort) including the mandatory final abort even when the import fails. The
  recovery claim — what this profile restores and, literally, what it does NOT
  — is printed before acting and again in the outcome; when a frozen
  authorization plan matches this checkpoint it is that plan's claim,
  unchanged. `--writers-excluded` is required, because the checkpoint contains
  its own promotion lease row and a lock inside the database being imported
  cannot protect the window. Code-first ordering is enforced, not advised: a
  checkpoint taken around a code phase refuses a database import until code is
  reconciled to the pre-release revision, which the refusal names. Only an
  SSH-adopted target carries the rollback authority runtime.
  See [docs/guides/recovery.md](../docs/guides/recovery.md).

- **`wprism recover <env> prepare --restore=<checkpoint> --operation-id=<id>
  --format=json`** — emits a canonical, read-only `wprism-recovery-plan/v1`.
  The plan binds the active signed target generation and receipt, actual
  encrypted checkpoint bytes, complete resource/effect scope, literal claim,
  topology, code head, stable target operation identity and actor-authority
  policy. It takes no writer exclusion, consumes no authorization and performs
  no recovery step. Retained, scoped, terminal or otherwise incomplete
  identities refuse instead of being promoted into executable-looking plans.

- **`wprism recover <env> execute --plan=<plan.json>
  --authorization=<signed-envelope.json> --format=json`** — verifies the
  external actor statement over that exact plan, re-observes every frozen fact,
  proves the complete configured provider/adapter set and receipt-bound
  recovery evidence still pass read-only preflight, proves the local signing
  secret matches the target-installed receipt key, and repeats current actor
  trust/signature verification as the last controller step before consuming
  authorization target-side. The target holds the rollback lock while it
  compare-and-consumes the frozen head, target record, signed event chain,
  receipt, checkpoint, trust policy and claim clock, and durably elects the exact authorization for
  the operation tuple. It then resumes exact open/completed provider operations
  and publishes one durable
  `wprism-recovery-outcome/v2`. Exact completion replays after expiry;
  consumed-without-completion refuses for reconciliation instead of retrying.
  A different envelope for the tuple is never an exact replay, whether its
  elected predecessor is nonterminal or complete.

- **`wprism explain <env> <selector> [--format=json]`** — rebuilds the current
  plan under a strict observation boundary and traces one itemized entity row
  through its compiled source shape, effective policy/manifest declarations,
  outbound declared reference edges, exact rebuild surfaces, selected
  structured actions, and convergence verifier. Run human `wprism plan <env>`
  and copy its indented, hash-safe `EXPLAIN` selector. JSON uses the separate
  `format:"wprism-explain/v1"` contract; plan/status JSON remains unchanged.

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

- **`wprism capabilities <env> [--operation=<op>] [--surface=<surface>]`
  `[--format=json]`** — resolves the repository's exact manifest pins against
  the reviewed dispositions in each
  [`adapter-packages/<slug>/package/`](../adapter-packages/). It evaluates the
  adapter's authored status, the operation, the exact state surface, and the
  installed plugin version against the reviewed window. The document's
  `schema_version` is `wprism-capability-report/v1` — the retired
  `wprism-capability-registry/v2` string was not reused, because no row carries a
  generated adapter digest, a subject certification record or a bound evidence
  status any more, and a consumer pinned to the old version would read those
  absences as data loss. It is projected from three inputs: the reviewed
  disposition per pinned manifest, the per-adapter provenance the catalog
  observed (source, trust tier, certification state), and — over a live target —
  `TargetProbe::probe_target()`. With no target it is the source/authorship gate
  only. The report carries one `registry_sha256`, the content address of the
  reviewed bytes the verdict was read from, which is the number an accepted
  contract pins and re-observes. Output separates unmodified plugin execution
  from branchable authored-state scope and gives structured blocker codes for
  uncertified sources, unpinned certifications, unsupported surfaces, and
  plugin version/activation cases; the WordPress, PHP, database and theme axes
  are deliberately not reported per surface, because the measured record that
  bounded them is gone and re-deriving them from the shipped platform note
  would be a guess. `--revision` is gone rather than inert: it selected an
  evidence-bound platform revision, and no record binds one. The agent-level
  `wp wprism capabilities --all` reports the complete shipped library. `wprism status`
  and host deploy/promote consume the same reviewed claims; `make release-gate`
  (`tools/capability-doc.php --check`, then `tools/classmap-generate.php
  --check`) proves the public prose in `docs/capabilities.md` still regenerates
  byte-for-byte from those same manifests and dispositions.

- **`wprism plan|explain|apply <env> [flags...]`** — streams the corresponding
  `wp wprism plan|explain|apply --repo=<repo_path> [flags...]` command for that
  environment. Ordinary flags are forwarded verbatim — e.g.:

  ```
  wprism apply e2 --adopt-by-slug=terms --default-author=admin --with-deletes
  ```

  `--scope-contract=<local-path>` is the one deliberate exception for plan
  and apply: the host validates that local canonical file with the engine
  parser and sends only its normalized selectors and `scope_hash` as compact
  evidence. Scoped plan is strict read-only observation; it may inspect a
  selected provider's identity/capabilities but invokes no effect. Scoped apply creates
  a separate target/lease-bound `wprism-scoped-mutation-authority/v1`, journals
  authored and native/provider effects under `wprism-scoped-apply-session/v1`,
  reconciles lost and already-journaled effects by exact operation ID/input
  hash, and runs a fresh bounded verifier which opens and compares the exact
  active session rather than trusting caller-supplied roots. It advances selected ledger rows plus its terminal
  receipt only; the receipt binds their post-finalization identity-map root,
  while global `applied_revision` and unrelated recovery debt are untouched.
  Nested widget/menu-item map membership follows its selected owning
  sidebar/menu and is sealed as opaque hashes before mutation, so removals and
  recovery cannot reclassify those rows or expose target UUIDs on the wire.
  Full plan/apply refuse while that session is nonterminal.
  Triggerless actions, legacy regenerators, environment-local provider
  deletion/reparent context channels, attachment metadata generation,
  code/lifecycle work, ordinary promotion, and rollback are not silently
  widened into this slice; they refuse or remain whole-revision operations.
  The narrow SSH checkpoint-only promotion profile below consumes this exact
  scoped apply protocol without adding those excluded effects.

  stdout/stderr stream live (not buffered/reformatted) and the exit code is
  exactly the agent's exit code. When `--format=json` reaches the agent, every
  agent command that advertises `--format=json` answers with one JSON record on
  stdout and a non-zero exit — the whole set, not an enumerated subset, so a
  missing or contradictory argument is as machine-readable as a policy gate:

  ```json
  {
    "format": "wprism-command-refusal/v1",
    "ok": false,
    "command": "capture",
    "error": "incomplete_state_discovery",
    "reason_code": "incomplete_state_discovery",
    "message": "capture found state that has no reviewed classification",
    "remediation": "review the diagnostics with wprism pending, then classify or exclude every named surface before another capture"
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
  human-facing `wprism: ` prefix is never machine-publication authority: every
  unclassified Throwable contributes none of its message, cause, path,
  login, or trace and instead sets `details_redacted: true` (issue #3404).
  On an eligible target the redacted sentence is not lost: the agent writes a
  private, gitignored `wprism-private-refusal-evidence/v2` record under the site
  repository's `.wprism/refusals/`, and the host prints one stderr line naming
  that place whenever a captured transport returns such an envelope. The same
  store also retains provider/recovery causes hidden behind a safe typed or
  human-facing wrapper. V2 contains a bounded Throwable graph (maximum 256
  scanned nodes, 512 edges, 64 recorded nodes, 4,096 retained source bytes per
  class/message/file field, 131,072 graph bytes, and 262,144 bytes for the
  complete record). Each bounded field includes its original byte length,
  SHA-256, truncation flag, and encoding. Valid UTF-8 stays verbatim; any other
  retained byte prefix is stored reversibly as base64 rather than silently
  substituted. The traversal object says explicitly whether nodes, edges,
  bytes, invalid edges, or cycles made the record incomplete. It contains no
  trace. The record is written only into a directory that is **already a
  WPrism repository** — a regular `site.wprism.json`, or a `.wprism/` that
  already exists — reached without following a symlinked repository root. The
  exact root and qualifying marker
  or control-directory inode are rebound around exclusive 0600 creation;
  `.wprism/refusals` is 0700 and its existing parent must be non-writable by
  group/other or sticky-bit bounded. For `init`, recording is allowed only when
  that directory was already a WPrism repository *when the command started*
  and is still the same directory (issue #3516, issue #3522). Both init conditions are
  entry-time facts on purpose: `init` is the command that CREATES that marker,
  so asking at refusal time let an init that published `site.wprism.json` and then
  failed answer its own question — and the `.wprism/` it wrote then survived the
  rollback that reported the repository restored. A **fresh** `init` therefore
  records nothing; its evidence is the sealed attempt journal and a human-mode
  rerun. An `init` recovering an interrupted attempt inside a real repository
  still records. A
  refusal must never materialize WPrism state in a directory that just failed
  the repository-identity gate, or in one that is not a WPrism repository at
  all, so where those conditions do not hold nothing is written and never
  replaces the primary refusal. The envelope is byte-identical either way: it
  carries `details_redacted: true` and never an evidence path. Known
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

- **`wprism capture <env> [--target-branch=<name>] [--scope-contract=<local-path>] [flags...]`** — retains
  the same streaming agent command, but the optional contract path is consumed
  by the host and is never forwarded to the target. The host validates the
  canonical `wprism-scope-contract/v1` with the engine parser, then sends only its
  normalized selectors and hash through the isolated control plane. The target
  recompiles and re-resolves the contract before observation. Repository-writing
  capture derives the caller's current named Git branch by default, or consumes
  explicit `--target-branch`, and the agent checks that exact target branch both
  before and under its publication lock. Detached callers and mismatched target
  branches refuse before state publication; capture never switches the target.
  The legacy `--out` determinism path writes no repository state and therefore
  carries no branch binding. A bounded capture
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

- **`wprism scope <env> --roots=<selectors> [--contract]`** — resolves a
  target-independent closure from explicit live roots. The default remains
  the human/`wprism-scope/v1` preview. `--contract` instead emits canonical,
  self-verifying `wprism-scope-contract/v1` evidence: normalized selectors,
  resolved live/tombstone identities, closure/inbound/excluded byte hashes,
  filtered uploads/media, exact eligible surfaces, and only potential
  action/provider/effect declarations. It is not a plan, provider
  negotiation, guard witness, or mutation authorization. `all` includes every
  compiled tombstone; a bounded deletion-intent row is named only as
  `tombstone:<uuid>`. The host runs both modes through the isolated WPrism
  control plane (`--exec`, `--skip-plugins`, `--skip-themes`), so ordinary
  plugins, themes, and MU code cannot run before the read-only compile.
  Direct `wp wprism scope --contract` without that isolated bootstrap refuses.

- **`wprism merge-check [--ref=<ref>] [--against=<ref>] [--base=<ref>]
  [--format=json]`** — the env-free half of the merge story, and another
  verb here that takes no `<env>`, no transport and no registry. With no
  `--against` it compiles ONE committed ref with the real repository compiler
  and reports structural coherence, surfacing the compiler's own refusals
  (duplicate uuid, dangling typed reference, unverifiable media catalog entry,
  code lock digest mismatch) with no live target. With `--against=<ref>` it
  compiles that ref, `--ref` (default `HEAD`) and their merge base, and hands
  the three to the SAME `RefreshPlan::plan()` refresh uses: `--ref` is the
  branch side, `--against` is the production side, `--base=<ref>` overrides the
  computed merge base. Exit codes are the contract — 0 coherent/no conflicts,
  1 refusal, 2 usage, 3 conflicts present. 3 is an answer: under
  `--format=json` it emits the `wprism-merge-check/v1` SUCCESS document with
  `verdict: "conflicts"`, while every refusal emits `wprism-command-refusal/v1`,
  so a machine caller gets exactly one parseable document per outcome. Each
  ref's `code/wprism-code.lock.json` is compared and any version skew is reported
  in `code_skew[]` as a warning that never changes the exit code, matching
  `wprism apply`. The plan is explicitly advisory (see the planner contract
  below), so it can never drive `wprism rebase`; a dirty canonical partition is
  refused rather than answered from the last commit; and field-level diff and
  scoped mode are deliberately absent because both need evidence only a live
  production export can produce.

- **`wprism refresh <production-env> --production-ref=<ref>
  [--scope-contract=<local-path>]`** — gets `P` only
  through `wp wprism refresh-export --repo=<repo_path> --format=json`; it never
  uses capture/apply/promote or treats a Git tree as live production truth.
  The target repo’s HEAD must equal the locally resolved production ref and
  must have no tracked changes before *and after* export. The exporter’s
  completed code descriptor must also match the compiler result for that ref:
  descriptor bytes prove deployed code, while the ref proves topology. The
  resulting B/P/W plan is persisted immutably under the repository’s Git
  common-dir (`wprism-refresh/plans/`), so orchestration evidence never dirties
  canonical state or requires a `.gitignore` rule. With a scope contract, the
  target associates it with that exact clean production ref and exports only
  selected state/media; everything else is explicitly `omitted_not_absent`.
  The planner treats those omissions as B, reports only in-scope conflicts,
  and records the exact contract/hash in its immutable plan.

  `--field-diff` is an explicit, unscoped-only opt-in. It leaves the private
  `wprism-refresh-plan/v1` and its `plan_hash` unchanged, then writes a separate
  immutable `wprism-refresh-field-diff/v1` projection under
  `wprism-refresh/field-diffs/<diff_hash>.json`. The projection is
  display-only/non-authorizing and value-free: it contains opaque selectors,
  closed entity and field labels, change categories, B/P/W presence/equality
  relations, role names, and binding hashes—never a path, stable identity,
  B/P/W literal, per-value hash, body, meta, option, or user value. It
  decomposes only ordinary plan entries whose B/P/W record category is
  `conflicting`; branch-only, production-only, and compatible rows remain in
  the ordinary private plan/counts because they need no field choice.
  `--format=json` is available only with
  `--field-diff` and emits exactly that one redacted object (or the standard
  `wprism-command-refusal/v1` envelope). Human output similarly says values are
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
  pair) and term `name`, `description`, and `parent`. Attachments/media,
  menus, sidebars, options, user-meta, typed tables,
  tombstones, and opaque containers stay one record choice.

  A changed post body composes only as a whole-top-level-block byte swap, and
  only when all three sides are pure block documents describing the same
  sequence — equal block counts, the same block name at every position, and
  identical bytes between the blocks. Then each changed block joins the
  `post.body.production_blocks`, `post.body.branch_blocks`, or
  `post.body.compatible_blocks` partition for its category and is applied
  automatically, so two editors working on different blocks of one page get
  both edits instead of an ours/theirs coin flip. The persisted diff publishes
  none of a block's bytes, position, or count; the explicitly privileged local
  TTY comparison may show the selected block bytes before continuing. Everything else
  about a body is one record choice under its own named reason: a body that is
  not a pure block document is `body_changed`, one whose block sequence
  differs on any side (insert, delete, reorder, retype, reflowed spacing) is
  `body_structure_changed`, and one where both sides changed the same
  top-level block differently is `body_block_overlap`. Nothing is merged
  inside a block. A live B record
  with an absent P or W side refuses field mode before a diff or choice is
  published; use the legacy whole-record resolver for that absence. Field mode
  also refuses scoped plans or missing/skewed B/P/W policy evidence rather
  than guessing whether a derived field is authored.

- **`wprism rebase <production-env> --production-ref=<ref> --new-branch=<name>
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
  the separate target-bound authority/session described above. Ordinary
  promotion, lifecycle/code materialization, and rollback do not accept it;
  the separate SSH checkpoint-only scoped-promotion profile is documented
  below.

  The legacy whole-record `--strategy`/`--resolve` contract remains unchanged.
  Alternatively, an unscoped run may supply
  `--field-resolution=<local-path>` naming a non-empty, at-most-1 MiB regular
  non-symlink canonical JSON file, or use `--interactive`; neither may be
  mixed with any `--strategy` spelling or `--resolve`. A field resolution is a
  separate `wprism-refresh-field-resolution/v1` list of complete opaque
  field-or-record selectors bound to the exact plan, production snapshot, and
  redacted diff. Interactive mode accepts `b`/`branch` (ours) and
  `p`/`production` (theirs). It requires TTY stdin and stdout. For each changed
  selector, its privileged local-only comparison shows base, branch, and
  production state, exact path, and every source value through a terminal-safe
  preview capped at 4 KiB (paths at 512 bytes), retaining the exact byte count
  and SHA-256 when truncated. It may also show one bounded C0/DEL-safe authored
  title/name or path fallback. Because these values can contain secrets or
  personal data, use only a trusted terminal and treat its transcript as
  sensitive. Comparison bytes and labels remain process-local and are never
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

`cli/src/Refresh/Refresh.php` has no plugin-specific or raw-Git state merge logic. A
`WPrism\Orchestrator\RefreshPlan` implementation provides these static methods:

1. `normalizeProductionSnapshot(array $export): array` validates and
   canonicalizes `wprism-refresh-production/v1` including semantic records,
   deletions, media, policy identity, and completed code evidence.
2. `compileGitWorktree(string $path, string $commit, string $role): array`
   compiles offline using the repository compiler. Roles are `base`, `branch`,
   `production-code` and `candidate` for refresh/rebase — the third exposes
   code identity only, never P — plus `merge-check-base`, `merge-check-left`
   and `merge-check-right`, which are the same B/W/P positions assembled
   entirely from Git by `wprism merge-check`. The merge-check roles are separate
   tags rather than reuses of the refresh names so an advisory artifact can
   never read as a live refresh one in a log or a stack trace;
   `merge-check-base` takes the same `compile_for_diff()` mode `base` does.
3. `assertProductionCodeMatches(array $production, array $productionCode): void`
   proves exporter/target compiler metadata and completed descriptor/revision
   match the exact production ref.
4. `plan(array $base, array $production, array $branch, array $context): array`
   and `normalizePlan(array $plan): array` emit a canonical, hash-bound
   `wprism-refresh-plan/v1` that includes the supplied B/P/W context.
5. `materialize(array $plan, string $worktree, array $resolution): array` and
   `validateMaterialization(array $receipt, array $plan, string $worktree): void`
   resolve only canonical state/media, reject unhandled stable conflicts,
   strict-compile, and return `wprism-refresh-materialization/v1` with matching
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

A plan whose hash-bound `context` carries `advisory: true` (with
`production_source: 'git-ref'` and no `production_env` /
`production_snapshot_hash`) is a merge-check plan and is NOT production
authority. Context is inside the bytes `plan_hash` covers, so the marker
cannot be removed without changing the hash, and
`Refresh::assertAuthorizingPlan()` refuses such a plan at both materialization
entries. `wprism merge-check` therefore reports and never mutates: it opens no
run journal, creates no candidate worktree, and writes no ref.

The host rejects any materializer change outside `state/` and `media/`; native
Git code conflicts remain in the disposable worktree. Plugin semantics belong
in manifests, native actions, or plugin-owned providers—not this shell.

- **`wprism lint <env> [extra wp-cli flags...]`** — read-only passthrough to
  `wp wprism lint --repo=<repo_path> …`. It lets the operator follow capture's
  lint warning without knowing the target transport or repository path;
  findings stream live and the agent's exit code is preserved.

- **`wprism env-set <env> --name=<name> --stdin`** — guarded
  passthrough to `wp wprism env-set --repo=<repo_path> --name=<name> …`, same
  live-streaming/exit-code contract as capture/plan/apply above. This is
  the one passthrough verb where that matters for more than consistency:
  interactive `--stdin` disables echo while the host reads exactly one
  newline-terminated line from the local terminal, restores the terminal, and
  only then starts the target with detached, piped stdin. Docker/SSH therefore
  cannot strand the operator in a hidden prompt if startup fails, and their
  target process intentionally has no PTY; direct target use retains the
  agent's own masking. If local masking or stdin isolation cannot be enabled,
  interactive host use refuses. Piped input has no terminal echo and passes
  through unchanged. While echo is masked,
  temporary HUP/INT/QUIT/TERM/TSTP handlers restore it before termination or
  suspension; a resumed prompt masks it again. Interactive use refuses if PHP
  cannot supply that signal coverage. Once the complete value is handed off,
  termination signals are reported and deferred until the target returns;
  WPrism therefore reports the target's real outcome instead of claiming a
  cancellation while a remote mutation may still be running. WPrism bounds the
  complete detached handoff and outcome wait at five minutes. If that deadline
  expires, WPrism terminates
  its local transport process, exits `75`, and states that the remote write may
  still complete. Retrying the identical value is safe because the target-local
  intent is published before the WordPress write and readiness stays red until
  live state matches it. Ctrl-Z suspends the local wrapper and foreground
  child/transport; a non-PTY Docker/SSH target may continue remotely. `fg`
  resumes the local outcome wait so WPrism can still report what happened.
  Nonblocking pipe handoff keeps those local signal handlers responsive even
  when the target is slow to read. The detached pipe ensures the target does
  not inherit the terminal as stdin. An
  incomplete pipe write is rejected by the
  agent instead of being stored as a truncated value. The value never enters
  argv.
  Named
  `--stdin`, not `--prompt` — wp-cli reserves `--prompt` globally for its
  own generic per-parameter prompting and consumes it before any command
  ever sees it, confirmed live rather than assumed. See "Env-bound value
  provisioning" below for what this command is for.

- **`wprism deploy <env> [--force-code-mismatch] [--force-code-drift]`** — the
  standalone lifecycle/code path. It compiles once into the target's
  `.wprism/artifacts/` directory, checks that artifact's standard plugin/theme
  runtime headers against exact target-control-plane PHP/WordPress evidence,
  then begins an exact target owner/artifact session; it deliberately takes no
  database checkpoint. Runtime requirements and target values remain outside
  the artifact and are reacquired before each new lease.
  The artifact has a state revision and may have a separate, opaque code
  descriptor/revision; its `artifact_hash` binds both. When that descriptor is
  present, the host carries the one frozen artifact and expected outer hash
  through `wp wprism code-preflight`, then binds those with the generated owner
  through `wp wprism code-stage` → fresh-process lifecycle retirement → fresh-process
  lifecycle activation → `wp wprism code-finalize`. It does not interpret
  descriptor fields or mutate
  files itself — those checks and mutations belong to the target agent. An
  artifact without a code descriptor retains the established lifecycle-only
  deploy path. Stop-on-first-failure and the target command's exit code apply
  to every phase. A `code_revision_stale` finding is recovered only by this
  host workflow; force flags may override explicit lifecycle compatibility or
  drift findings, never the verified code-before-state ordering witness.
  The target database lease serializes WPrism writers only. Operators must exclude
  package managers, self-updaters, and other direct `WP_CONTENT_DIR` writers
  during stage/finalize; stable symlinks are refused, but this v0 PHP
  materializer is not an adversarial filesystem-race sandbox.

- **`wprism promote <ssh-env> --scope-contract=<local-path> [--with-deletes]`**
  — a separate checkpoint-only scoped promotion profile. The host validates
  the local contract, sends only compact selectors plus `scope_hash`, compiles
  once, and requires a strict scoped plan. The first profile accepts only
  options, declared snapshot tables, sidebars, user meta, and option/table
  tombstones; posts, terms, menus, attachments, actions/providers, code and
  lifecycle work, and every force flag refuse before authored mutation.

  Before target apply, the SSH recovery runtime holds a v2 exclusion covering
  public traffic, background jobs, package/filesystem writers, and every
  database writer, then prepares an encrypted whole-database checkpoint and
  prior-world verifier. Adoption pins the installed agent to that protected
  control root. Target begin, apply, and completion independently verify the
  active signed receipt, exact owner/artifact/scope/payload hash, and held
  exclusion; a caller-supplied compact request or receipt hash is never
  authority by itself. The target session is a random `ps-*` generation with
  closed `scoped-checkpoint-v1` metadata. The signed receipt and target
  mutation authority also bind the exact `--with-deletes` capability and a
  hash-only external generation tuple, so a new receipt cannot replay an old
  terminal for the same scope/artifact and a no-delete window cannot be
  widened target-side.

  Success records the scoped terminal receipt, seals the external generation
  through `verifying_new` to `committed` while exclusion remains held, retires
  the exact target handoff, and only then releases the exclusion. Response-loss
  retries reuse that same generation, delete capability, and terminal receipt.
  Before durable `scoped_fresh_verification`, failure can run only the
  checkpoint `database_restore` and `prior_verify` path and finishes
  `rolled_back`; after that forward-only seal, rollback is never guessed and
  no later public scoped rollback is offered. Local/Docker drivers and direct
  target-local scope contracts refuse this host-only profile.

- **`wprism promote <env> [apply flags...]`** — the normal fail-closed
  code-and-state promotion path. It compiles the repository once into
  `.wprism/artifacts/`, runs the same descriptor-bound target runtime preflight,
  acquires a target-DB lease bound to that artifact's outer
  `artifact_hash`, then exports the target database into `.wprism/checkpoints/`.
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

  Fatal-safe control commands (`compile`, `code-preflight`, `code-stage`, `code-finalize`, lease
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
  `wprism_state` base. `wprism status` exposes this as non-zero
  `INCOMPLETE_LIFECYCLE`. Only the exact original owner/artifact may re-begin
  for the documented checkpoint import sequence; it cannot continue a
  materializer, lifecycle, or apply phase while the receipt remains.

  It stops on the first non-zero phase and compensates with an exact,
  idempotent lease abort. A failed export is not presented as a usable
  checkpoint. Every successful checkpoint intentionally contains the temporary
  `promotion_lock` row, because the lease existed before export. A database
  import can replace that row and therefore cannot itself be serialized by a
  lock stored inside the imported database. Recovery first requires external
  maintenance/exclusion for every WPrism writer, then four ordered commands:
  exact abort of the old owner/hash (idempotent), re-begin that owner/hash,
  a fatal-safe isolated `wp db import <checkpoint>` which skips plugins,
  themes, and user MU code, and a final abort of the row restored by the
  import—even when import fails. The first abort refuses if a newer session
  superseded this checkpoint, instead of presenting an obsolete dump as a safe
  recovery source; `wprism recover` reports that refusal as
  `promotion_abort_session_superseded` with its remedy, on the failed step and
  in `wprism-recovery-outcome/v1`. After a code-enabled failure, first reconcile or
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
  .wprism/
  ```

  Deploy remains a distinct lifecycle window where activation hooks fire;
  apply remains the separately canary-armed, hook-free state window.

- **`wprism pending <env>`** — runs `wp wprism pending --repo=<repo_path>
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
  numeric id it can point at a specific entity). Clearance-flagged items get
  a prominent trailing `[SECRET: hard:<label>]` / `[SECRET: suspicious]`
  or `[PII: <label>]` marker; these are redacted categories, never values.
  Exit 0 always; prints "review queue is empty" when there's
  nothing to triage. Evidence includes owner candidates, representative
  value shapes, counts, and the reason capture is blocked where those are
  known; the journal enriches evidence but is not required for completeness
  on declared surfaces. `wprism pending <env> --format=json` is a raw passthrough
  of the agent's own JSON (same precedent as `wprism status` vs. `wprism plan
  --format=json`) for scripting.

- **`wprism classify <env>`** — interactive triage of the review queue, one
  item at a time, reading decisions from **stdin** (not `/dev/tty` — so
  it's pipe-testable: `printf 'r\n' | wprism classify e1` drives it exactly
  like a keypress would). For each item it prints the same evidence/
  proposal/ref-hint/clearance block `wprism pending` shows, then prompts:

  | Key | Effect |
  |---|---|
  | Enter | accept the item's proposal (only offered when one exists) |
  | `a` / `r` / `e` / `d` / `m` | explicitly classify `authored` / `runtime` / `env` / `derived` / `managed` |
  | `s` | skip this item (leave it pending) |
  | `q` | quit — stop triaging and apply whatever was already decided |

  **The clearance rule**: choosing (or accepting a proposal of) `authored` on
  a secret- or PII-flagged item never goes through on Enter alone — it prints
  a red warning and requires typing the literal word `allow` for each
  applicable clearance before that decision is added to the batch; anything
  else skips the item. Enter-accept can never silently author a secret or
  personal data into git.

  **The options completion**: an options row classified `authored` or
  `managed` gets one more closed prompt for `autoload`, and one classified
  `env` gets one for the boolean `required` — the two fields the agent's site
  grammar refuses the rule without. Both re-ask on anything unrecognized and
  offer `s` to skip the item; neither is ever defaulted, and the skip is what
  keeps a half-made decision out of the batch. Nothing is asked for a
  `post_meta`, `term_meta`, `user_meta` or `scope` row, where the grammar
  reads neither field.

  **Ref attachment**: an `authored` decision on an item carrying a
  `ref_hint` gets one more y/N prompt — "attach `ref=<kind>` to this
  classification?" — before moving on. Declining leaves the field authored
  but untyped.

  All decisions are batched into a **single** `wp wprism classify --repo=<repo_path>
  --set=<section>:<key>=<class>[,ref=<kind>][,autoload=<flag>][,required=<bool>][,allow_secret=true][,allow_pii=true] […]`
  call at the end (not one call per item) — its output streams live and its
  exit code propagates. A reviewed clearance remains on its exact row through
  that call. The target re-reads every live value, so one row's approval can
  never authorize a sibling that became sensitive after the host read the
  queue.
  A final `N classified, M skipped.` line summarizes the session. Exit 0
  immediately with "review queue is empty" if there was nothing to triage.

- **`wprism classify <env> --accept-proposals`** — non-interactive, for CI/
  scripting: accepts every item that has a proposal, exactly as proposed,
  in one batched call. The one exception is absolute: a secret- or PII-flagged
  item proposed `authored` is **never** auto-accepted — it's skipped loudly
  (its `section:key` and redacted category printed to stderr) because that decision
  needs a human. Ref-hints are never auto-attached in this mode either
  (attaching a ref is the judgment call the interactive y/N prompt exists
  for). Exit 0 if the queue was empty or every proposal-bearing item got
  accepted; exit 2 if any clearance-flagged authored item had to be skipped, so a CI
  pipeline can tell "nothing to do" apart from "a human needs to look at
  this."

  An options row whose proposal is `authored` (or `managed`/`env`) is skipped
  the same way and for the same kind of reason: a journal proposal reports
  which capability wrote the value on which surface, which says nothing about
  how the row must be stored or whether an operator provisions it, and this
  mode does not invent the missing half. It prints the `section:key`, the
  proposed class and the field it would need, and exits 2. Rows in other
  sections in the same queue still apply.

  ```
  wprism classify e1 --accept-proposals
  ```

- **`wprism classify <env> --export-batch=<path>` / `--apply-batch=<path>`** —
  reviewed bulk triage for an aged site's first queue, where historical writes
  cannot have journal proposals. Export writes a value-redacted
  `wprism-classification-batch/v3` artifact: every row retains the pending
  evidence and has editable `class`, `ref`, `cast`, `allow_secret`, and
  `allow_pii` fields,
  and an options row additionally has `autoload` and `required`. It refuses to
  overwrite an existing file. Fill every `decisions[].class`, review any
  ref/cast and clearance overrides, then apply the same path. Apply fetches the
  live queue again and verifies the artifact's environment and SHA-256 binding
  before opening the one batched remote policy write. A partial review,
  changed queue, unsupported manifest/schema surface, malformed rule, or
  unacknowledged authored secret or PII value is a mutation-free refusal. If a valid
  decision exposes a new pending item, apply reports that next queue and exits
  2; export and review a new batch.

  An options row is not complete at `class` alone, because the agent's site
  grammar will not load the rule it produces: `authored` and `managed` need
  `autoload` (`preserve` to replay the source row's own flag, or a literal
  `yes|no|auto|on|off|auto-on|auto-off` to pin it), and `env` needs the boolean
  `required` (`true` when an operator must provision the value on a fresh
  environment). Both are exported as `null` and neither is ever prefilled from
  evidence: an observed autoload flag is an observation, while the rule is a
  contract capture enforces. An unfilled one refuses on the host naming the row
  and the field; a field the class does not read (`autoload` on a `runtime`
  row, either one on `post_meta`) refuses rather than being recorded as a
  decision nothing acts on. A `wprism-classification-batch/v2` artifact has no
  `allow_pii` review field, while v1 also lacks the storage decisions; both
  refuse with re-export as their named remedy. The interactive and
  `--accept-proposals` modes follow the same rule:
  triage asks for the field, and `--accept-proposals` skips an options row
  whose proposed class needs one (a journal proposal is a class signal and
  carries no storage decision), reporting it and exiting 2 the way it already
  does for secrets.

  ```sh
  wprism coverage production --format=json > coverage.json
  wprism classify production --export-batch=production-review.json
  $EDITOR production-review.json
  wprism classify production --apply-batch=production-review.json
  wprism pending production
  ```

  The queue hash deliberately binds evidence as well as identities. Do not
  hand-edit `queue_sha256`: when the site changes, export a fresh artifact so
  the reviewed facts and the applied decisions stay the same transaction in
  the operator's reasoning.

  <sub>Implementation note: all decisions travel in a single semicolon-
  joined `--set` value (`--set 'post_meta:foo=runtime;options:bar=authored,ref=post'`),
  never as repeated `--set=<spec>` flags — wp-cli's assoc-arg parser keeps
  only the *last* occurrence of a repeated flag, confirmed against
  `agent/src/Command/Cli.php`'s `classify()` docblock. `allow_secret=true` and
  `allow_pii=true` travel inside only the reviewed row; the legacy
  command-wide `--allow-secret`/`--allow-pii` spellings are accepted only for
  a single-row direct command and refuse a joined multi-row set.
  `ClassifyCommand`'s
  `SET_MODE` constant (`cli/src/Command/ClassifyCommand.php`) is the one place that
  decision lives.</sub>

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
        "format": "wprism-local-control-plane/v1"
      }
    }
  }
}
```

`repo_path` is required for every environment, on every transport — it's
the site-repo path **as seen from inside that environment** (a container
path, a remote path, or a local path), passed straight through as
`wp wprism <verb> --repo=<repo_path>`.

Per-transport required keys:

| Transport | Required keys | Optional keys |
|---|---|---|
| `local` | `wp_path`, `repo_path` | machine-local-only exact `bootstrap: {"format":"wprism-local-control-plane/v1"}` |
| `docker` | attachment: `compose_file`, `service`, `repo_path`; existing-service bootstrap adds `wordpress_service`, `wp_path`; managed bootstrap uses `compose_file`, `wordpress_service`, `tooling: "managed"` and generates the helper fields | `compose_env_file`, `profile`, `mode` (`"run"` default, or `"exec"` for an existing service) |
| `ssh` | `host`, `wp_path`, `repo_path` | `ssh_config`, paired `rollback_key_id` + `rollback_signing_key`, `rollback_recovery`, `verified_rollback` |

A missing required key is a loud, specific error naming the environment,
the key, and the transport — never a guess.

`compose_env_file` is an optional machine-local path passed to Compose as
`--env-file` before `-f`. It is useful for parameterized Compose definitions
whose project name, mounts, or ports must remain fixed across fresh `run --rm`
calls; the path must exist when the registry entry is loaded.

The local/Docker `bootstrap` member grants only the delivery mechanism. It is accepted
only from an untracked `.wprism-envs.json` entry whose provenance is assigned by
the registry loader; the same bytes in checked-in `site.wprism.json` remain
unsupported. A present bootstrap object is closed and exact—`null`, another
format, or any extra key is a configuration error. `wprism driver-capabilities`
does not contact WordPress. The later `wprism adopt` command emits and binds a
`wprism-bootstrap-eligibility/v1` report for the exact target before installation.
For Docker, use `connect --tooling=managed` when the application Compose file
has no WP-CLI service. WPrism builds its pinned controller-owned helper image,
writes a private generated overlay without copying resolved environment values,
and gives it a separate durable repository volume. The original Compose file is
not edited, application services are never started or stopped, and helper runs
use `--no-deps`. The running official WordPress service must have a writable
persistent `/var/www/html`; custom images and mounts that shadow the MU control
path refuse. Advanced users may name an existing WP-CLI/Git-capable service;
the same local-daemon, running-web, storage, and initial-authority checks apply.

### Optional branch-environment provider

Physical production snapshots, resource creation/cleanup, URLs, and host TTLs
are privileged host operations, not transport primitives. An environment may
therefore add this exact block only in the machine-local `.wprism-envs.json`
entry (checked-in `site.wprism.json` is rejected even if it tries to forge loader
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
`wprism-branch-environment-provider-request/v2` object on stdin and must return
one canonical `wprism-branch-environment-provider-response/v2` object on stdout.
Protocol 2 has a closed capability vocabulary. It deliberately replaces v1:
`repository-materialize` now requires the provider to echo the distinct named
`target_branch` it checked out. Because `capabilities` is always the first,
non-mutating action, a v1 provider is rejected before snapshot, resource, or
repository mutation. Upgrade both wire format strings and `provider.protocol`
before running materialization.

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
operation-bound session/lease receipt. While that freeze is held, WPrism exports
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
`wprism-environments/` with mode-0600 immutable run/event records; it is
operational recovery state and never canonical branch state.

`ssh_config`, when present, is passed to both `ssh -F` and `scp -F` and may be
relative to the registry file that defined the environment. This is the
single place to configure a non-default port, identity, proxy jump, and
host-key policy without embedding shell options in `host`.

`rollback_key_id` and `rollback_signing_key` are optional as a pair. The key
path resolves relative to the registry file, must be a regular mode-`0600`
file, and contains canonical base64 Ed25519 secret-key bytes. Keep it in the
gitignored machine-local overlay. `wprism adopt` derives and installs only its
public key under `<repo_path>/.wprism/control/public-keys/`; a key id is immutable,
so rotation uses a new id. The controller secret is never copied to the host.

SSH adoption also installs the database-independent recovery runtime under
`<repo_path>/.wprism/control/recovery-runtime/` and creates one stable external
target identity. `wprism status` verifies the active signed receipt and complete
event hash chain. An invalid chain or any active nonterminal state is
non-green; only `committed` and `rolled_back` active generations are green.
`wprism deploy` and `wprism promote` refuse target mutation while that external
authority is invalid or nonterminal. A host adopted before this runtime emits
a manual-recovery warning and retains the operator-directed behavior.

When both the rollback verification key and recovery configuration are
installed, adoption also writes a mode-`0600`
`<mu-plugin-dir>/wprism/scoped-promotion-control.json`. It contains only the
absolute adopted control root and a closed format tag. Receipt-bearing target
commands cannot choose another root: they run the installed raw recovery
runtime, verify the signed active chain, and re-prove the live v2 exclusion
before begin/apply/complete. Runtime/provider stderr and opaque exclusion
tokens never enter public JSON.

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
preflight, `wprism promote` selects the automatic profile by those capabilities,
binds the compiled plan's exact code/upload/effect inventories into the
provider preparation and receipt v2,
and uses the signed operation journal. Any missing capability produces a loud
WARN and retains the operator-directed checkpoint path; a configured but
failed preflight refuses instead of degrading.

The SSH scoped-promotion profile uses the same policy, exclusion provider, and
checkpoint provider but prepares no code/upload/effect release evidence. Its
exclusion provider must attest the v2 `database_writers: true` scope in
addition to the other four scopes. That is a whole-target promise covering
direct database clients, workers, integrations, and migration tooling—not
just public HTTP or WordPress cron.

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

`wprism` merges **two** optional files. It searches upward for `site.wprism.json` so
commands work from any subdirectory, but inside Git accepts that file only at
the current worktree root. It then accepts an automatically discovered
machine-local overlay only beside that site file. When no site file exists,
the overlay must be at the current Git worktree root. A nearer nested registry
or overlay is refused instead of being allowed to shadow the trusted
environment names; use the explicit overlay override only when you
intentionally trust another path:

1. **`site.wprism.json`** — the site repo's own policy file (see
   [spec/repo-format.md](../spec/repo-format.md)). Committable: the `envs`
   entries here should contain nothing secret (no passwords, no bare API
   tokens) since this file is meant to be shared with the whole team via
   git. This is the natural home for the list of environments that *exist*
   for this site (e.g. `dev`, `stage`, `prod`) and whatever about them is
   true for everyone.
2. **`.wprism-envs.json`** *(gitignored)* — a machine-local overlay, same
   shape (`{"envs": {...}}`), for anything that's true only on this
   machine or shouldn't be committed: a local docker-compose file path, an
   ssh alias only you have configured, a sandbox-only environment nobody
   else needs.

**The overlay wins whole-entry, per environment name** — if `stage` exists
in both files, `.wprism-envs.json`'s `stage` entirely replaces
`site.wprism.json`'s (no per-key deep merge). This keeps the merge rule simple
and predictable: for any given environment, exactly one file is "the"
source of truth, and it's always the more machine-specific one when both
define it.

Relative filesystem paths inside an environment entry (currently just
`compose_file`) resolve against the directory of **whichever file defined
that entry** — not the current working directory — so a registry file
keeps working no matter where you invoke `wprism` from.

Pass `--envs-file=<path>` to explicitly trust and load the overlay at that
path instead of searching for `.wprism-envs.json`. Auto-discovery refuses a
Git-tracked `.wprism-envs.json`, because repository content cannot authorize a
privileged host provider; the explicit flag is an operator trust decision and
must never be populated from an untrusted repository or script.
`site.wprism.json` discovery is unaffected by this flag — it's specifically an
override for the machine-local half of the registry.

### Suggested `.gitignore` line

```
/.wprism-envs.json
/.wprism/*
!/.wprism/authority/
/.wprism/authority/*
!/.wprism/authority/authorities.json
/.wprism-env-values.json
```

(Already added to this repo's `.gitignore` for the sandbox.)

## Transports

All three transports build a fully `escapeshellarg()`-escaped command
string and run it either streamed (`passthru`, exit code propagated — used
by `capture`/`plan`/`apply`) or captured with stdout and stderr collected
on **separate** pipes (`proc_open`, used by `doctor`/`status`, which parse
output — `docker compose run`'s own container-lifecycle chatter lands on
stderr, and merging the streams would corrupt the JSON `wprism status` parses).

- **`local`**: `wp --path=<wp_path> wprism <verb> --repo=<repo_path> …`
- **`docker`**: `docker compose -f <compose_file> [--profile <profile>] run --rm -T <service> wp wprism <verb> --repo=<repo_path> …`
  (default, or explicit `"mode": "run"`)
- **`ssh`**: `ssh -T <host> 'cd <wp_path> && wp wprism <verb> --repo=<repo_path> …'`
  (the remote command is assembled with each part escaped, then the whole
  thing is escaped again as the single argument to `ssh`; `-T` defeats a user
  `RequestTTY=force` setting)

Raw (non-`wp`) commands, used only by `doctor`'s reachability, repo-path,
and `.wprism-env-values.json` git-tracked checks, follow the same shape but run through `bash -c '<script>'` for
`docker` (so shell operators like `&&`/`[ -d … ]` work — `docker compose
run`'s trailing arguments are otherwise passed as the container's argv
directly, not interpreted by a shell) and directly for `local`/`ssh` (PHP's
`proc_open`/`passthru` already invoke `/bin/sh -c` for string commands, and
`ssh` already hands its command argument to the remote login shell).

### `docker` transport mode (issue #3513)

`"mode": "exec"` is an opt-in per-environment key that switches every
`docker` command from `run --rm` to `exec` against an **already-running**
service:

```
docker compose -f <compose_file> [--profile <profile>] exec -T <service> wp wprism <verb> --repo=<repo_path> …
```

and the raw-command shape follows the same substitution (`exec -T <service>
bash -c '<script>'` in place of `run --rm -T <service> bash -c '<script>'`).
Any `mode` value other than `"run"` or `"exec"` is a loud, specific error
naming the environment, the key, and the offending value — never a silent
fallback to `run`. Leaving `mode` unset, or setting it to `"run"`, produces
byte-identical command strings to every environment defined before issue #3513.

**Why opt in.** `run --rm` pays container create plus (on the legacy
`sandbox/docker-compose.yml` estate, which uses `depends_on`) dependency
resolution plus wp-cli's own startup, on every single call — measured on one
host at roughly 0.40s + 0.51s + 0.33s. `exec -T` against a container that is
already up pays only the wp-cli startup floor, measured at roughly 0.14s —
about 0.77s saved per call on that estate, or about 0.26s per call on a
`sandbox/pair.sh` pair (which has no `depends_on` to begin with — see
`docs/sandbox.md`).

**What it costs.** Unlike `run --rm`, `exec` does not create a fresh
container per call:

- wp-cli's own cache/tmp state persists across calls instead of starting
  clean every time.
- Any edit to the service's environment variables or mounts goes stale until
  the operator recreates it (`docker compose up -d --force-recreate
  <service>`, or an equivalent).
- The service must already be running. `wordpress:cli`'s default CMD (`wp
  shell`) exits immediately without a TTY, so a service meant to stay
  resident for `exec` needs its own long-running `command:`, e.g. `command:
  ["tail", "-f", "/dev/null"]`.

**The not-running precondition.** Before building an `exec` command,
`DockerTransport` checks once per `wprism` process whether the target service
is running (`docker compose … ps --status=running --services`); the result
is cached for the rest of that invocation, so a single `wprism` call never pays
that probe twice. If the service is not running, every command — `wp`, raw,
and the env-set stdin handoff alike — fails with the exact remedy instead of
reaching docker at all:

```
env '<name>': transport mode "exec" requires service '<svc>' to be running. remedy: docker compose -f <compose_file> [--profile <profile>] up -d <svc>
```

so `wprism doctor <env>` renders `[FAIL] transport reachable — …` with that
remedy as its first check, the same way any other unreachable transport
does. It never falls back to `run`.

Container user stays whatever the service's own `user:` is (`33:33` for the
sandbox's `cli-*` services); `exec` does not change that. The `--exec`
control-plane bootstrap (`CodeDeploy::controlArgs`, used by `compile`,
`promotion-*`, `code-stage`, `code-preflight`, `code-finalize`, `deploy`,
`scope`) is per-process wp-cli state and is unaffected by container
residency. `env-set`'s stdin piping (`PassthroughCommand::streamWpInput`)
works the same way over `exec` as over `run`: `-T` is passed explicitly on
both, so stdin is a plain pipe rather than a TTY even against an older
compose that would otherwise allocate one.

## Env-bound value provisioning

issue #3232. A manifest can classify an option `class: "env"` — a value that
is genuinely per-environment (a payment gateway API key, `siteurl`, an
`admin_email`) and must therefore **never** be captured or applied like an
ordinary authored value; doing so would let one environment's value
silently overwrite another's the next time someone runs `wprism apply`. Every
`class: "env"` rule also carries a mandatory boolean `required`
(`Policy::validate_env_options()` refuses to load a manifest that omits
it): `true` means an operator must hand-provision this value on every
fresh environment (a genuine secret or site-identity value with no sane
default); `false` means it's plugin-internal bookkeeping that
self-populates the first time its owning plugin runs (a version marker, a
one-shot install-state flag) and is not worth checklisting — see any
shipped manifest's own `options` section for real examples of both.

Env values are never captured into branchable state. `env-set` instead records
the intended value in this target checkout's owner-readable
`.wprism-env-values.json`, so planning can distinguish correct configuration from
an arbitrary non-empty value:

- **`wprism plan` / `wprism status`** surface an `env_missing` bucket: every
  declared `class: "env"` option whose live value is absent or empty. A
  required option also appears when it has no target-local intended binding
  or differs from that binding. Each row carries its `required` flag. A
  *required* miss makes `wprism status` exit non-zero ("not safe to
  promote"); an *optional* miss is listed for visibility only. This is a
  per-environment equality check; raw secret values never enter the plan.
- **`wprism env-set <env> --name=<name> --stdin`**
  provisions one value directly, bypassing capture/apply entirely
  (`Apply::set_env_option()`). It refuses any `--name` the loaded policy
  didn't declare `class: "env"`, refuses an option that declares
  `sub_keys` (a structured, plugin-managed blob — Yoast's `wpseo`,
  Polylang's `polylang` — that a bare string write would corrupt; every
  such option shipped today is `required: false` for exactly this
  reason), and refuses an empty value (which `env_missing` would
  immediately re-flag as still-missing). The terminal LF and its optional CR
  are stdin framing; every other leading/trailing space or tab remains a value
  byte, so a whitespace-only line is non-empty. It atomically publishes the intended
  value with mode `0600` before writing WordPress; a stopped or failed write
  therefore leaves visible drift, never a false-green unbound value.
  Interactive host `--stdin` masks the
  local terminal for one host-side read, restores it, then starts the target
  with detached piped stdin and sends only that line; direct target use masks at the
  agent, and piped input has no terminal echo. The host restores echo before normal,
  exceptional, HUP, INT, QUIT, TERM, and TSTP exits/suspension, re-masks after
  resume, and refuses interactive use without the required signal support.
  After handoff, termination is deferred while the target has a chance to
  report its real outcome. The host bounds the complete detached handoff and
  outcome wait at five minutes; expiry returns temporary-failure exit `75`
  and states that the remote write may still complete. Retry the identical
  stdin value: publishing intent before the WordPress write makes `env-set`
  idempotent, and plan stays red until the live value matches. Ctrl-Z suspends
  the local wrapper and foreground child/transport together, but a non-PTY
  Docker/SSH target may continue; `fg` resumes the local outcome wait.
  The pipe handoff itself is nonblocking so local signal handling remains live
  while a slow target applies backpressure.
  The value is never printed or logged
  (not `--prompt` — see the passthrough section above for why that name
  was unavailable); its own "value for '&lt;name&gt;': " prompt writes to
  STDERR, never STDOUT, so `--stdin --format=json` is still safe to pipe
  into a JSON parser. `--value` is refused because flags land in shell
  history and process listings; scripts also pipe one newline-terminated
  value through `--stdin`.

### `.wprism-env-values.json` (target-local intended-value authority)

`wp wprism env-set` records what each required environment option is intended to
contain without putting secret bytes in branchable state. It publishes a flat
`{"option_name": "value", ...}` `.wprism-env-values.json` living next to
`site.wprism.json` inside **one environment's own checkout** (not on the
orchestrator host — contrast `.wprism-envs.json` above, which is
machine-local to wherever you *run* `wprism` from and covers every
environment at once; this file, if it exists, lives on the target itself
and covers only that one environment). The file is canonical JSON, is written
atomically with mode `0600`, and must be a regular non-symlink file with no
group or world access. It ships in
`sandbox/site-repo.gitignore.template` (every managed site repo's own
`.gitignore`) and is checked by `wprism doctor <env>`'s git-tracked hygiene
check (a tracked secrets file is a blocking failure, not an advisory
one). That check runs *inside* the target environment, so it needs a
`git` binary there to inspect tracked status with — most environments
materializing ordinary `wp wprism` commands have no structural reason to carry
one, while the `wprism init` workflow explicitly requires it. This project's
base sandbox images verifiably omit Git, so outside init the check degrades to an honest
advisory "could not verify" in that case rather than a false-clean PASS
— see `cli/src/Onboarding/Doctor.php`.

Plan reads this file and requires exact equality for every `required: true`
option. A live value that is absent, has no binding, or differs from the
binding remains in `env_missing`; a wrong, stale, or cross-client credential
therefore cannot be green merely because it is non-empty. Optional options
remain presence-only because the shipped `sub_keys` options are plugin-owned,
self-populated structures that scalar `env-set` deliberately refuses.

`env-set` publishes the intended binding before mutating WordPress. If the
process stops between those operations or WordPress rejects the write, plan
shows the mismatch until the operator retries; it never accepts a live value
whose intent was not recorded. `agent/src/Kernel/Secrets.php` does not scan
this file: its job is to stop secret-shaped bytes from entering captured
state, while this file is explicitly outside capture and protected by file
permissions plus the git-tracked hygiene gate.

### Provisionable bindings

`env-set` accepts declared flat env options and canonical post-password or
typed-column input-file bindings. It does not provision arbitrary post/term
meta or a `sub_keys` carve-out's individual keys. Input names have the form
`column_file:<uuid>:<column>.<field>...` and come from Plan's `env_missing`
checklist. Supply one filename under the adapter's declared content directory
through `--stdin`; the private intent store receives it, then ordinary Apply
updates the saved pointer. Source filenames and file contents never travel
with repository state. Provisioning refuses while an authored Apply holds
input intent, and Apply rechecks file availability before commit. A later
plugin job remains responsible for consuming its local file.

## Proposed spec addition

The following is proposed language for `spec/repo-format.md` (not applied —
spec/ is the team lead's; this is a suggestion for the `site.wprism.json`
section):

> ### `envs` (optional)
>
> `site.wprism.json` may declare an `"envs"` object, keyed by environment
> name, describing the environments that materialize this site repo for
> the `wprism` orchestrator CLI (see [cli/README.md](../cli/README.md)). Each
> entry names a `transport` (`local`, `docker`, or `ssh`) and a
> `repo_path` — this site repo's path *as seen from inside that
> environment*. Entries here are shared via git and must contain no
> secrets (credentials, tokens, private hostnames an attacker could use);
> anything machine-specific or sensitive belongs instead in a gitignored,
> machine-local `.wprism-envs.json` overlay next to it, which replaces
> same-named entries whole. `envs` is orchestrator convenience, not part of
> the branchable state contract — the agent's `wp wprism …` commands
> (`spec/repo-format.md`'s actual subject) never read it.

A second suggestion, for wherever `spec/repo-format.md` documents a
manifest's `options` section and its `class` values (`authored`,
`runtime`, `derived`, `env`, `managed`) — unlike `envs` above, this one
*is* part of the branchable-state contract the agent itself interprets
(`agent/src/Policy/Policy.php`, `agent/src/Apply/Apply.php`), not orchestrator-only:

> #### `class: "env"` options (issue #3232)
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
> attention. `wp wprism plan`/`wp wprism status` surface every currently-unset
> `"env"` option as an `env_missing` entry tagged with its `required`
> flag; `wp wprism env-set` is the only sanctioned way to write one, and
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
real `platform/adapter-library/core/manifest.json` this example is modeled on
already has it) or
the example will no longer load under `validate_env_options()`.
