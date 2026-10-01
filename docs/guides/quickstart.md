# Connect and onboard an existing site

Start with the [disposable demo](try-wprism.md) if this is your first evaluation.
For an existing site, read [site eligibility](site-eligibility.md) and use a
rehearsal copy before considering a production operation.

This guide creates a dedicated local workspace, installs WPrism on the target,
and makes the first baseline reviewable through Git. The
[onboarding reference](../reference/onboarding.md) covers manual configuration,
lower-level commands, updates, and transports without bootstrap authority.

## Before you start

Use a full WPrism Git checkout and PHP 8.3 on the controller. The target must
already run a supported single-site WordPress installation. Check its transport,
filesystem, database, Git, and Git LFS prerequisites in
[site eligibility](site-eligibility.md) and the
[onboarding reference](../reference/onboarding.md#before-you-start).

Choose an absent local workspace path and an empty Git remote that both the
controller and target can reach with their own credentials. Keep the WPrism
source checkout separate from the site's code/state repository.

## Connect an existing site

From the WPrism checkout, save an absolute path to the CLI before entering
the new workspace:

```sh
WPRISM_CLI="$PWD/cli/wprism"
```

Choose the transport that matches your site.

### SSH

```sh
"$WPRISM_CLI" connect production --workspace=../my-site \
  --transport=ssh --host=deploy@wp.example.com \
  --wp-path=/var/www/html --repo-path=/home/deploy/site-repo
```

The target needs WP-CLI and the host tools named in the eligibility guide.
The configured repository path is the path seen on that target.

### Local Docker Compose

For a running service based directly on the official WordPress image, with
writable persistent `/var/www/html`, request managed controller tooling:

```sh
"$WPRISM_CLI" connect local --workspace=../my-site \
  --transport=docker --compose-file=./compose.yml \
  --wordpress-service=wordpress --tooling=managed
```

The private helper overlay uses the application's environment, networks, and
WordPress storage and owns a separate repository volume. The application
Compose file is not edited. The WordPress service must already be running.
Custom images or mounts that shadow the MU control path can refuse; read the
[Docker configuration reference](../reference/environments.md#transports)
for existing tooling services and `run`/`exec` modes.

`connect` checks transport reachability, installed WordPress, and single-site
topology before creating the workspace and its untracked `.wprism-envs.json`.
The topology check boots WordPress, so site startup code may run. Explicit
managed tooling also creates the disclosed helper resources.

## Create and publish the first baseline

Enter the connected workspace:

```sh
cd ../my-site
```

For the SSH connection:

```sh
"$WPRISM_CLI" onboard production --git-url=git@github.com:you/my-site.git
```

For the Docker connection, if the proposal needs database setup, explicitly
name and authorize its running database service:

```sh
"$WPRISM_CLI" onboard local --configure-database --database-service=db \
  --git-url=git@github.com:you/my-site.git
```

Database setup discloses and grants the server-wide `PROCESS` privilege to the
exact WordPress account only after proving the selected running database is
its server. Ordinary confirmation does not enable that setup. Use the detailed
reference for database identity requirements and hosts without this opt-in.

The sequence is **adopt → assess → init → Git handoff**. Adoption delivers the
agent with its embedded adapter library from the invoking checkout's
freshly assembled `agent recovery` archive. Assessment presents the proposed boundary
before initialization asks for confirmation. Review the scope and every refusal.

WPrism preflights the empty remote from both controller and target before
adoption/init writes. It publishes the target's initialized branch and checks
out that exact branch in the local workspace. The machine-local registry stays
untracked. The checkout gives you a reviewable baseline; it does not switch the
live target to an arbitrary branch.

## Resolve blockers or resume a handoff

If baseline commits were created but final status reports blockers, keep those
commits and resolve the reported gaps. For a required `env_missing` binding,
provision the deliberate target value through `env-set --stdin`, then read a
fresh `status`. Follow [assessment](assess.md) and
[environment-bound values](../reference/environment-values.md).

If initialization completed without a Git URL, resume only the handoff:

```sh
"$WPRISM_CLI" onboard local --handoff-only --git-url=git@github.com:you/my-site.git
```

Use `production` instead of `local` for the SSH example. Keep the same remote
URL when reconciling a lost response:

```sh
"$WPRISM_CLI" onboard local status --git-url=git@github.com:you/my-site.git --format=json
```

Status reconciles existing evidence. It does not repeat adoption or baseline
creation. Read [the onboarding reference](../reference/onboarding.md) when
init itself failed or recovery is uncertain.

## Start a reviewed change

Inspect the site's full assessment and status. Before capturing feature work,
point or materialize the development target at the intended named branch.
`capture` verifies that branch binding; it does not switch the target branch.

Continue with [assessment and contracts](assess.md),
[the daily workflow](daily-workflow.md), then
[release](release.md) and [recovery](recovery.md). A second environment needs
its own eligibility, local values, reviewed scope, and operation readiness.
