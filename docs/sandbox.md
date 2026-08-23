# Sandbox redesign (task #74): one pair template + one shared MariaDB

*Replaces the pattern (not the file — see "what stays on the legacy
mega-compose" below) of `sandbox/docker-compose.yml`: one dedicated MariaDB
container per env pair, ~10 pairs hand-duplicated as compose profiles. That
mega-file wedged the OrbStack daemon under three concurrent stacks this
session — the incident that motivated this redesign. New surface:
`sandbox/pair.yml`, `sandbox/db.yml`, three override files
(`sandbox/pair.{http,journal,codebind}.yml`), and the lifecycle tool
`sandbox/bin/pair.sh`.*

## The model

**One shared MariaDB server, many pairs.** `sandbox/db.yml` brings up a
single long-lived `mariadb:11` container (`duo-shared-db`, its own compose
project `duo-db`) that hosts every pair's databases. `sandbox/pair.yml` is
one generic pair template — services `wp1`, `wp2`
(`wordpress:7.1-php8.3-apache` by default) and `cli1`, `cli2`
(`wordpress:cli-php8.3`, `user: "33:33"`) — with no
MariaDB service of its own. Each pair is brought up as its own compose
project (`-p duo-<name>`), so any number of pairs come and go independently,
each in its own blast radius. Everything is driven through
`sandbox/bin/pair.sh`; nobody should invoke `docker compose -f pair.yml`
directly except pair.sh itself (it sets several env vars — `DUO_PAIR`,
`DUO_PORT1/2`, `DUO_CODEBIND_PLUGIN` — that the compose files need to
resolve correctly).

The generic pair's WordPress core image is pinned to the exact version
`docs/compatibility-baseline.json` records as last verified, so a floating
registry tag cannot silently move the platform boundary a proof runs against.
That file is the one project-level compatibility statement: the agent blocks
policy load outside its PHP/database ranges, or on a core outside its
WordPress range or inside it but on a minor line `verified` does not name,
`cli/src/Onboarding/Doctor.php` reports the same failures before orchestration,
and `tools/capability-doc.php` cross-checks it against
`manifests/capabilities/platform.json` so the two copies cannot drift.
`DUO_WP_IMAGE` is an explicit override for exploratory local work; a
candidate-bound run leaves it unset and therefore uses
`wordpress:7.1-php8.3-apache`.

Why one server instead of one-per-pair: a clean-room reset becomes `DROP
DATABASE` + `CREATE DATABASE` against a server that's already initialized
and warm, instead of removing a volume and paying MariaDB's full InnoDB
bootstrap again on next boot. Measured on this machine, warm image cache,
nothing else contending for resources (see "Measured reset time" below):
**~0.75s for the new reset vs ~7.8s for the old volume-cycle** — and the old
number excludes the `docker compose rm -sf` step the real
`sandbox/conformance/run.sh` also pays before its `docker volume rm -f`, so
real-world old-flow resets cost more than that baseline.

### Cross-project networking

`db.yml` owns an external docker network, `duo-shared` (declares it without
`external: true`, so its own `up` is what creates it). `pair.yml` and every
override attach to that same network with `external: true` — attach only,
never create. This is how `wp1`/`wp2`/`cli1`/`cli2` reach the shared server
at `WORDPRESS_DB_HOST=duo-shared-db` (the db's `container_name`, globally
unique on the docker host) despite living in a different compose project.
`depends_on` cannot cross that project boundary, which is exactly why
pair.sh's own readiness waits exist (below) instead of a compose-level
health dependency.

**Known constraint, not a bug**: `wp1`/`wp2`/`cli1`/`cli2` are the same
literal service names in *every* pair, all sharing the one `duo-shared`
network. Docker's embedded DNS does not scope a service-name-based alias
per compose project on a shared external network — resolving the bare name
`wp1` from inside any pair's container is not guaranteed to reach *that
pair's own* `wp1`. Nothing in this design relies on that resolution (the
only cross-container hostname anything depends on is `duo-shared-db`,
which is unambiguous); headless installs use an RFC 2606 `.invalid`
placeholder URL specifically to avoid ever needing it. Don't add code that
assumes `wp1`/`wp2` resolve to "your own" pair on this network.

### The shared user, grants, and per-pair database naming

Every pair gets two databases on the shared server: `wp_<name>1` /
`wp_<name>2` (e.g. pair `conf` → `wp_conf1`/`wp_conf2`). One application
user, `wordpress`/`wordpress`, is shared by every pair via a **wildcard
grant** — `pair.sh up` idempotently runs:

```sql
CREATE USER IF NOT EXISTS 'wordpress'@'%' IDENTIFIED BY 'wordpress';
GRANT ALL PRIVILEGES ON `wp\_%`.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
```

(`wp\_%` — escaped underscore, then a wildcard — matches every
`wp_<name>{1,2}` database any pair will ever create; no per-pair user, no
re-granting on every `up`.) Root credentials (`root`/`root`) are for admin
operations only (`CREATE`/`DROP DATABASE`), always via `docker exec
duo-shared-db mariadb -uroot ...` from pair.sh — never over the published
port. That port (`127.0.0.1:3316`, loopback-only) exists solely for a human
who wants to point a GUI SQL client at the fleet directly; no tooling here
depends on it.

Pair names are constrained to lowercase letters/digits, starting with a
letter (`pair.sh`'s `validate_name`) — used bare as both a MySQL identifier
fragment and a compose project suffix, so this one rule keeps every name
safe in both contexts without any identifier-quoting logic. Two names are
reserved and rejected outright: `db` (`duo-db` is this file's own shared-
MariaDB project — `pair.sh destroy db` would otherwise tear down the server
every other pair depends on) and `sandbox` (`duo-sandbox` is the legacy
mega-compose's project — same risk, against a file this tool must never
touch).

**mariadb:11 image note**: this image ships only the `mariadb` client
binary — no `mysql` symlink (confirmed while authoring this; MariaDB has
been renaming its client tools). `sandbox/lib/pair_db.sh`'s `pair_db_sql()`
uses `mariadb`, not `mysql`, and passes the password via `MYSQL_PWD` (still
honored) rather
than `-p`, avoiding the "insecure password on command line" warning.

## `pair.sh`

```
pair.sh up <name> <port1> <port2> [--journal] [--codebind <plugin-dir>] [--artifacts] [--wordpress-offline] [--http|--headless]
pair.sh reset <name>
pair.sh destroy <name>
pair.sh list
```

Run as `bash sandbox/bin/pair.sh ...` (this repo's shell is zsh; every
script here, this one included, is bash and always invoked explicitly that
way).

### The exact-source gate (`DUO_EXPECTED_SOURCE_SHA`)

`agent/` and `manifests/` are bind-mounted from the repo's **canonical**
checkout, resolved through git's own common-dir, never from wherever
`pair.sh` was invoked (DUO-3277 — a linked worktree is removed at close-gate,
which would kill a persistent pair's mount source out from under it). The
consequence for evidence: a live suite or `conformance/run.sh` sweep launched
from an issue worktree runs the **canonical checkout's** code, not the
branch's, and nothing used to compare the two (DUO-3316 lost a day to exactly
this — worktree at 3ae1ea5, pair mounted canonical b69fdf, and the resulting
stale-code warnings looked like a candidate regression).

`up`, `reset`, and `start` therefore always print the source they will mount
— path, HEAD, and where this `pair.sh` copy itself is running from — on
**stderr**, so it survives the `>/dev/null` most live suites wrap `pair.sh up`
in (provenance is not progress chatter). Export
`DUO_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD)` (7–40 hex; `run.sh` takes
`CONF_EXPECTED_SOURCE_SHA` and exports it as this) to turn that report into a
gate: the run refuses unless the mounted source is exactly that commit with
no uncommitted `agent`/`manifests` changes, and it refuses **before** the
budget reservation, the shared db, `DROP`/`CREATE DATABASE`, the site-repo
roots, and any container create/start. `start` verifies the source **baked
into that pair's existing containers** rather than what the common-dir
resolves today, since `compose start` reuses whatever a container was created
with. Unset, nothing refuses and behavior is unchanged — persistent-pair
workflows are deliberately not candidate-bound. `stop`, `destroy`, and `list`
are never gated: they are teardown and inspection, and cleanup must not be
blocked by a variable left exported in a shell. The refusal names its own
remedy — commit or stash, or "produce this evidence from a clean standalone
clone at the expected commit" (`sandbox/bin/pair.sh:372`), because uncommitted
mount bytes make a live result unreproducible and nothing records what they
were.

### `up` — idempotent bring-up

In order: report (and, when `DUO_EXPECTED_SOURCE_SHA` is set, verify) the
agent/manifests bind-mount source, before anything else at all; validate the
dynamic host CPU/RAM pair budget (before creating any
pair state); ensure the shared db is up and healthy; ensure the `wordpress`
user/grant exist; create this pair's two databases; create its site-repo
directories (and, under `--codebind`, the plugin subdirectory the bind
mount needs to exist before any container attaches to it — see below); bring
up `wp1/wp2/cli1/cli2`;
wait for DB-level readiness on both sides; run the generic WordPress
bootstrap (`core install`, theme, permalinks, `.htaccess`) on each side
*unless it's already installed*; print the pair's wp-cli invocation
pattern. Re-running `up` on an already-installed pair is safe and fast — it
re-converges the containers (a no-op if config hasn't changed) and skips
the bootstrap entirely.

**The readiness fix task #74 called for**: every script in this sandbox
(`setup.sh`, `conformance/run.sh`, the spike scripts) polls `wp core
version` in a `wait_for()` loop to decide when an env is ready. That command
reads a static PHP file — it never touches the database — so it reports
"ready" before the database connection is actually live; this session hit
exactly that gap. `pair.sh` delegates its bounded readiness observations to
`sandbox/lib/pair_readiness.sh`; WordPress installation, theme activation,
permalink/.htaccess setup, and reset-to-bootstrap state live in
`sandbox/lib/pair_bootstrap.sh`; exact pair-owned site-repository preparation,
uid-33 handback, inode-safe clearing, and codebind reset refusal live in
`sandbox/lib/pair_siterepo.sh`. It waits on two different things instead, both
real: the shared server's own healthcheck (`healthcheck.sh --connect
--innodb_initialized`, polled via `docker inspect`, since `depends_on` can't
cross the compose-project boundary to the shared db), and then, per side,
`wp db query "SELECT 1"` run through that side's own `cli` container. The
latter is deliberately not `wp db check` (`mysqlcheck`) — that inspects
*tables*, and right after `CREATE DATABASE` there are zero tables to check,
which would make it a false negative exactly when it matters most. A plain
query round-trip has no such blind spot.

**`--http` vs `--headless`**: `--http` (default) publishes `wp1`/`wp2` on
`<port1>`/`<port2>` and installs with `http://localhost:<port>` as the site
URL — what every existing script needs, since they curl the live site.
`--headless` publishes no host port at all and installs with an
unresolvable `http://<name>{1,2}.invalid` placeholder (see the DNS
constraint above for why an in-network hostname isn't used instead).
Nothing about wp-cli access depends on this flag either way — the printed
invocation pattern always goes through `docker compose run`, never HTTP.
Publishing ports is its own overlay (`pair.http.yml`) so a pair that will
never be curled can skip host-port consumption entirely — one less shared,
finite resource to collide over when several pairs run at once.

**`duo`'s `mode: "exec"` transport opt-in (DUO-3513, cli/README.md) is a
separate, host-CLI-level concern from the printed pattern above.** It lets an
environment in `.duo-envs.json` swap `docker compose run --rm` for `docker
compose exec` against an already-resident service, trading a fresh container
per call for wp-cli's own startup floor. `pair.yml`'s `cli1`/`cli2` are not
resident today: they carry no `command:` override, so `docker compose up -d
cli1` would start `wordpress:cli`'s default CMD (`wp shell`), which exits
immediately without a TTY. Pointing a docker environment's `mode` at `"exec"`
against a `pair.sh` pair therefore requires giving that service its own
long-running `command:` (e.g. `command: ["tail", "-f", "/dev/null"]`) first —
out of scope for this pass, which only adds the transport-level opt-in.

**`--journal`** layers in `pair.journal.yml`, which adds `DUO_JOURNAL` to
`WORDPRESS_CONFIG_EXTRA` on all four services (matching the exact define the
legacy compose file's `spikec`/`r1a`/`r1b`/`r1c` profiles already
use). **`--codebind <plugin-dir>`** layers in `pair.codebind.yml`, spike G's
pattern generalized: `wp-content/plugins/<plugin-dir>` is bind-mounted from
this pair's *own* `siterepo/<name>{1,2}/code/wp-content/plugins/<plugin-dir>`
tree instead of a shared static fixture, so a `git pull`/file edit on the
host is visible inside the already-running container immediately, no
restart. `pair.sh` pre-creates that directory (empty, host-owned) *before*
any container that mounts it exists — Docker auto-vivifies a missing bind
source as an empty root-owned directory otherwise, and an already-running
container's mount stays pinned to whichever directory/inode existed at
container-create time. Confirmed during self-test: copying a real plugin
file into the pre-created host directory made it appear inside the
already-running `wp1` container instantly, and `wp plugin activate` against
it worked for real (hooks fired). `up --codebind` also passes
`--force-recreate wp1 wp2` so an existing pair picks up a freshly re-authored
`code/` tree on a repeat `up`, not a prior run's stale mount (same fix spike
G's own script applies to itself).

**`--artifacts`** layers `pair.artifacts.yml` and bootstraps the exact
Twenty Twenty-One version in `conformance/artifacts.lock.json` from the shared
digest-addressed ZIP cache. The typed cache identity is
`<plugin|theme>-<slug>-<version>-<sha256>.zip`; a per-identity flock means
concurrent cold callers perform one download, and every cache hit re-hashes
the bytes before use. **`--wordpress-offline`** additionally maps the
WordPress.org catalog/download hostnames to loopback and requires
`--artifacts`; it also makes any artifact cache miss refuse before `curl`.
This overlay is the warm-cache proof mode for a live conformance run, not a
claim that ordinary WordPress itself is generally network-hermetic.

Compose's multi-file merge for `volumes:` is by target path, not whole-list
replacement — confirmed via `docker compose config` while authoring
`pair.codebind.yml` — so layering it on top of `pair.yml` only *adds* the
one new mount; it doesn't disturb the other four. `environment:` merges by
key, which is why `pair.journal.yml` has to repeat the
`WP_ENVIRONMENT_TYPE` line alongside `DUO_JOURNAL` rather than only adding
the new define — `WORDPRESS_CONFIG_EXTRA` is one scalar value, and the
override's value for that key wholly replaces the base's.

The two site-repo bind roots are mode `0777` by design. They are disposable
test directories jointly written by the host-side Git harness and the
container-side wp-cli user (uid 33), which creates top-level capture locks
and atomic staging directories. Linux bind mounts preserve the host runner's
ownership, so ordinary host-created `0755` directories would make capture
fail in CI. Conformance also invokes wp-cli with umask `000`, keeping its
captured descendants removable by the host-side harness. These exceptions
are confined to disposable sandbox paths and processes; they are not guidance
for production repository permissions.

### `reset` — what it covers, and what it deliberately doesn't

```
pair.sh reset <name>
```

Drops and recreates both of the pair's databases, clears the contents of its
site-repo directories in place (`siterepo/<name>{1,2}`), and removes
`siterepo/origin-<name>.git` — the same clean-room scope
`conformance/run.sh` currently hand-rolls per manifest run. The two site-repo
root inodes are deliberately preserved so ordinary web/site bind mounts
cannot retain a deleted-directory inode with stale content. If a live or
stopped pair container has the additional nested codebind plugin mount,
`reset` refuses before touching databases or files; destroy the pair and
bring it back with the original `--codebind` flag instead. It does **not**
touch the `wp1`/`wp2` webroot named volumes,
does **not** restart or recreate any container, and does **not** re-run
`core install`. This is a deliberate, narrow scope: the webroot volume was
never the slow part (the official WordPress image's entrypoint re-templates
`wp-config.php` from the current environment on every container start
regardless of what's already on disk, so a persisted webroot carries no
staleness risk), and WordPress doesn't hold a persistent DB connection
across requests, so an already-running container transparently sees the
freshly-recreated, empty database on its very next wp-cli call or HTTP
request — no restart needed for reset to take effect. The next `core
is-installed` check (whether via `pair.sh up` again or a caller's own
install routine) correctly sees an uninstalled site and proceeds.

### `destroy` — full teardown, minus site-repo history

```
pair.sh destroy <name>
```

`docker compose -p duo-<name> -f pair.yml down -v --remove-orphans`
(containers + the pair's own named webroot volumes; never touches the
external `duo-shared` network or the shared db) plus `DROP DATABASE` on
both of the pair's databases. `siterepo/<name>{1,2}` is left on disk
untouched — intentionally asymmetric with `reset`: destroy means "this name
is done," and the existing convention throughout this sandbox is that
site-repo directories are never auto-deleted on teardown (every historical
spike's `siterepo/` still sits there, inspectable, long after the spike
finished). Remove them by hand if you want the name fully gone.

### `list`

Shows live pairs and the shared db's status. Filters `docker compose ls`
by `ConfigFiles` containing `pair.yml`, not by project-name pattern —
the legacy compose file's own project is literally named `duo-sandbox`,
which (being lowercase letters only) would otherwise pass right through a
naming-convention filter and get miscounted as one of this redesign's own
pairs. Confirmed this was a real, not hypothetical, bug during self-test.

### The concurrency budget

`up` and `list` use the host's dynamic CPU/RAM budget (one active pair per
available budget unit). Before `up` creates a database, site-repo root, or
container, it takes a crash-safe kernel file lock in the canonical checkout's
ignored `sandbox/siterepo/.pair-budget.lock`, re-enumerates Compose projects,
and holds that reservation through web/CLI container creation. Linux uses the
host `flock` utility; macOS/BSD uses the host's already-used `python3` and
`fcntl.flock` through a control FIFO. Git linked worktrees therefore share one
budget gate. A new pair that would exceed the verified budget is refused and
names the existing pairs; set
`DUO_PAIR_BUDGET_OVERRIDE=1` only when the operator deliberately accepts the
load. An already-live pair may still be re-converged. If Docker capacity,
Compose enumeration, `jq`, or both supported lock backends (`flock` and
Python `fcntl`) are unavailable, the command fails closed without guessing
capacity or creating pair state. The
kernel releases the descriptor after a crash/signal; the lock file itself is
never removed, so a later owner cannot delete another process's reservation.
`list` uses the same serialized strict query and surfaces the budget warning.
Variable presence is only permission, never proof of use: `pair.sh`
initializes a private ledger through `sandbox/lib/pair_force_hatch.sh` and
appends to it (`pair_force_hatch_record`, `pair.sh:524`) only when
the unreserved over-budget branch actually consumes the override. An in-budget
admission with the variable present therefore remains unforced. If actual use
cannot be recorded, pair admission refuses before its first post-budget
mutation — a run that forced its way past the budget has to stay
distinguishable from one that did not.

## The destroy-when-green convention

Going forward, an agent that brings up a pair.sh-managed pair for its own
work is expected to `pair.sh destroy <name>` it once that work is green —
the old convention of leaving finished spike/grind pairs running
indefinitely (still visible today: `a`, `b`, every `r1a*/r1b*/r1c*`, `conf*`
all still up on the legacy file from earlier this session) is exactly what
made three-stacks-at-once an ordinary occurrence rather than an edge case.
This convention applies to pair.sh-managed pairs only; it does not
retroactively apply to anything already running on the legacy
`docker-compose.yml`, and destroying one of *those* pairs is out of scope
for whoever manages this redesign — see below.

## Scaffolding a fixture's own site-repo `.gitignore` (DUO-3244)

Every fixture that scaffolds its own throwaway site-repo needs an inner
`.gitignore` inside it (`siterepo/<pair>N/.gitignore`) — a SEPARATE file
from the outer duo-wp repo's own `.gitignore`, since the inner one governs
what git tracks *inside* the nested fixture repo. Copy
`sandbox/site-repo.gitignore.template` into place (`cp
site-repo.gitignore.template siterepo/<pair>N/.gitignore`, from `sandbox/`
as cwd) rather than hand-rolling a `printf`: a narrower, independently
hand-typed pattern is exactly what caused DUO-3244 (missing
`state.capture.lock` — one of agent/src/Publication/Publish.php's capture-publication
artifacts alongside staging, backup, intent, and receipt records — hit a real,
structural "local changes would be overwritten by
merge" failure the first time a test captured on both sides of a pair
before a `git pull` on the second side). If the pattern this template
covers ever needs to change, change it once, there, not across every
fixture's own copy.

## The five execution classes under `sandbox/tests/`

Which gate runs a suite is its directory, not its name. `offline/<domain>/`
(267 files across fifteen subject domains) is the merge-gate corpus: no
docker, no pair, `make regress-offline-all` runs every one of them anywhere.
The other four sit outside that gate, and — with the one exception named
below — every file in them needs a live pair. `live/` (43 files) is
per-mechanism pair evidence, one pair each, enumerated by `make
regress-live-list` and run when the diff touches the mechanism a suite's own
header names. `grind/` (11) holds the scenario grinds that walk a whole
fixture site through a round of work (`grind_r1a_forms.sh` and its siblings),
plus `grind_ecommerce_developer.matrix.json` — a data file whose two readers
are offline suites, kept here with the harness whose stem it shares.
`certify/` (7) is live certification-style evidence, one mechanism apiece: the
merge, version-skew-merge, deletion, version and adversarial matrices and the
two SSH adoption/rollback proofs. `spike/` (10) is the hand-run family — the
exploratory `spike_*.sh` seeds, the three docker smokes whose own headers
require an already-booted spike-E pair (`cli_smoke.sh`, `cli_triage_smoke.sh`,
`lint_smoke.sh`), and the two scripts with no `Makefile` target at all.
`check_guide_commands.sh` is hermetic and could have been an offline suite; it
is here because its header (`:37`) claims exactly this kinship — "deliberately
has no Makefile target: same precedent as `cli_status_truth.sh`" — and
`offline/` is reserved for what the merge gate executes. Nothing in the gate
runs it; `php tools/offline.php --extras` does, opt-in.

`sandbox/tests/` itself holds no suites, only `fixtures/`, `lib/`, `support/`
and the corpus wrapper `offline_diagnostics_guard.sh`. The split is enforced,
not conventional: `regress_suite_wiring.php`'s clause 4 refuses a suite wired
to a target of any class but its own directory's, and refuses a recipe of one
class that names a helper sitting in another's.

## What stays on the legacy `sandbox/docker-compose.yml` this round

Untouched, on purpose: the mega-compose file itself, every pair it defines
(`a`/`b`, `c`, `e1`/`e2`, `f1`/`f2`, `conf1`/`conf2`, `fx1`/`fx2`, `g1`/`g2`,
`r1a*`, `r1b*`, `r1c*`), `sandbox/setup.sh`, every
`sandbox/tests/spike/spike_*.sh` and `sandbox/tests/grind/grind_*.sh` script,
and `sandbox/conformance/run.sh`'s *env
provisioning* only gets migrated once tasks #72/#73/#75 (the sibling
engine-gap work using the conformance pair concurrently) are all complete —
see the git history / task board around task #74 for the migration status
of `run.sh` specifically. Migrating the spike/grind scripts themselves to
pair.sh, and deleting the mega-compose file, are explicitly **follow-up**
work for a later round, not this one. Nothing here removes or renames
anything in `docker-compose.yml`.

## Measured reset time: DROP/CREATE vs the old volume cycle

Measured back-to-back on this machine, warm image cache, no other load —
i.e. a best case for the *old* approach, since the incident this redesign
responds to was specifically about behavior *under contention*:

| approach | steps timed | wall time |
|---|---|---|
| new (`pair.sh reset`) | ensure shared db healthy (already warm) + `DROP`/`CREATE DATABASE` ×2 + `rm -rf`/`mkdir -p` site-repo dirs | **0.75s** |
| old (per-pair volume cycle) | `docker rm -f` container + `docker volume rm -f` + fresh `docker run` + poll until the MariaDB healthcheck reports healthy | **7.83s** |

The old number is a lower bound on what `conformance/run.sh` actually pays
today — it excludes the `docker compose rm -sf` step that precedes the
volume removal in the real script, and excludes any contention with other
running stacks. The ~10x gap is entirely the InnoDB bootstrap
(`mariadb-install-db` + system-table creation) that a brand-new, empty
datadir must pay and an already-initialized, already-warm server does not.

## Self-test evidence

Exercised end-to-end against scratch pairs (`sbx1`/`sbx2`/`sbx3`, ports
8830–8835, all destroyed afterward) before this was considered done: plain
`up`; idempotent re-`up` on an already-installed pair (correctly skipped
reinstall); `--journal` (confirmed `DUO_JOURNAL` actually defined via `wp
eval`); `--codebind` (confirmed the live bind-mount propagation into an
already-running container, and a real `wp plugin activate`); `--headless`
(confirmed no host port published, `.invalid` URL used); the >2-pair
warning (confirmed firing with the right pair names, both from `up` and
`list`); `reset`; `destroy` (confirmed containers/volumes/databases all
removed, site-repo directories correctly left behind, the shared db and
`duo-shared` network correctly left running); resource caps (confirmed via
`docker inspect` — `mem_limit`/`cpus` on `pair.yml`'s services and `db.yml`
actually reach the container's `HostConfig`, not just parsed and ignored).
The legacy `docker-compose.yml` pairs (`a`, `b`, `conf1/2`, `r1a*/r1b*/r1c*`)
were confirmed still running, untouched, throughout.

## Live conformance evidence

The subject-certification apparatus this section used to describe — the
`duo-subject-certification-bundle/v1` records, `sandbox/certification/`, the
`certify-subject-bundle` / `certify-subjects-parallel` runners, and the
registry import that published them — is retired. No content-addressed bundle
stands behind a product claim any more, and no byte change expires anything.

What stands behind a claim now is a reviewed entry in
`manifests/dispositions.json` plus live conformance that is run continuously
rather than sealed into a record. `make conformance-<name>` runs one entry
(`sandbox/conformance/run.sh <name>`; the entries are
`sandbox/conformance/entries/*.json`), and `CONF_EXPECTED_SOURCE_SHA` is how a
sweep binds itself to a commit — `run.sh` exports it as the
`DUO_EXPECTED_SOURCE_SHA` the gate above enforces.

The narrower live fixtures keep their own targets, each with its rationale in
the `Makefile` beside it: `certify-merge`, `certify-version-skew-merge` (the
cross-branch plugin-version-skew scenario `certify-merge` routes away from
itself), `certify-adversarial-matrix`,
`certify-deletion-matrix`, `certify-version-matrix`
(`VMATRIX_MANIFEST=<name>` names the manifest whose `version_range` edges get
installed — `sandbox/tests/certify/certify_version_matrix.sh:52`),
`certify-ssh-adoption-roundtrip` and `certify-ssh-rollback`. They keep the
`certify-` prefix for their history; each is a live proof of one mechanism,
and none of them publishes a record.

Everything above about the pair budget, the exact-source gate, the artifact
cache and destroy-when-green applies to those runs unchanged: those are
properties of the pair, not of the retired apparatus.
