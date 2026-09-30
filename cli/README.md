# WPrism CLI

The dependency-free PHP orchestrator connects a local Git workspace to WordPress
through local, Docker, or SSH transports. Use PHP 8.3 for the documented setup.
Install the full source checkout: the executable loads its sibling sources,
and adoption assembles the agent and adapter library from that checkout.

## Usage

From the WPrism checkout:

```sh
cli/wprism --help
cli/wprism help connect
cli/wprism help release
cli/wprism help all
```

The overview shows the main workflows. Command help gives that command's
syntax and details; `help all` is the complete command reference. Help runs
locally without reading a site registry or contacting a target.

After connecting a site, keep an absolute path to `cli/wprism` while working
inside the site workspace. The [quickstart](../docs/guides/quickstart.md)
shows that setup. Composer is required for developing WPrism, not for running
the CLI.

## Choose a workflow

| Task | Guide |
|---|---|
| Try a page change on two disposable sites | [Demo](../docs/guides/try-wprism.md) |
| Check whether a host and site qualify | [Site eligibility](../docs/guides/site-eligibility.md) |
| Connect a site and create its first baseline | [Quickstart](../docs/guides/quickstart.md) |
| Review scope, unsupported surfaces, and readiness | [Assessment](../docs/guides/assess.md) |
| Capture, review, and reconcile feature work | [Daily workflow](../docs/guides/daily-workflow.md) |
| Stage, authorize, execute, and verify a release | [Release](../docs/guides/release.md) |
| Prepare recovery and understand its loss boundary | [Recovery](../docs/guides/recovery.md) |
| Change plugin or theme code | [Code updates](../docs/guides/code-updates.md) |
| Contribute support for a plugin | [Adapter authoring](../docs/guides/adapter-authoring.md) |

## Configuration and output references

- [Environment registry and transports](../docs/reference/environments.md):
  local overlay provenance, required keys, Docker modes, SSH options, and
  branch-environment providers.
- [Environment-bound values](../docs/reference/environment-values.md):
  intended-value authority and provisioning through `env-set --stdin`.
- [Command contracts](../docs/reference/cli-commands.md): detailed behavior,
  machine receipts, exit codes, private refusal evidence, and the semantic
  planner contract. Use built-in help for command and flag syntax.
- [Onboarding reference](../docs/reference/onboarding.md): explicit bootstrap,
  lower-level adoption/init, manual seeds, updates, and a second environment.
- [Repository format](../spec/repo-format.md): normative data and wire contracts.

## Before a write

Work in the intended named branch of the connected site repository. Read the
complete assessment and plan, provision required local values, and resolve
reported gaps before granting execution authority. A successful connection or
baseline does not qualify every operation on the site.

The demo exercises a bounded page apply. For a production release, follow the
release guide's source staging, preparation, external signed authorization,
execution, and verification sequence. Plan-only output is review evidence;
it does not grant write authority.

When a command refuses, retain its exact reason code and source commit.
Machine output is redacted; detailed private evidence stays on the target
and must be handled as private data. See the
[refusal-evidence contract](../docs/reference/cli-commands.md#plan) and
[SECURITY.md](../SECURITY.md).
