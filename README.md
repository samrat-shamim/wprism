# WPrism

WPrism is an open-source tool for reviewing and publishing selected WordPress
content and settings from a test site to a live site.

Working on a test copy of a site, often called **staging**, gives you room to
prepare changes before visitors see them. Meanwhile, the live site keeps
receiving orders, comments, and other activity. Copying the whole database from
staging can overwrite that newer activity. WPrism separates the content and
settings you want to publish from the data that belongs to the live site.

For example, an agency updating a client's homepage can:

1. Edit the page on staging using the usual WordPress editor.
2. Review the captured changes with the team before publishing.
3. Apply the approved page change to the live site, keeping live orders and
   comments in place.

WPrism is built first for **WordPress agencies and developers** managing sites
with staging environments. It currently uses a command-line tool and Git to
store changes as reviewable files with a shared version history. Site owners
and editors can keep working in WordPress while their developers handle that
workflow.

Support depends on the site's plugins, versions, and the changes being made.
WPrism checks the agreed scope and blocks unsupported operations;
[capabilities and limits](docs/guides/capabilities-and-limits.md) explains those
boundaries.

**Status: pre-1.0 alpha.** Start with the disposable demo. Outside production
adoption is still being validated; a passing demo or a reviewed adapter does
not qualify an entire site. Read [site eligibility](docs/guides/site-eligibility.md)
before connecting a real host.

## Try it

Use a full source clone with PHP 8.3, Docker with Compose, Git, and `jq`.
Composer is needed only to develop WPrism. The first run downloads the pinned
container images.

```sh
git clone https://github.com/samrat-shamim/wprism
cd wprism
cli/wprism demo start
cli/wprism demo review --accept-page-only
```

Open the printed **source** wp-admin URL and edit **WPrism Demo Page**. Then:

```sh
cli/wprism demo capture
git -C sandbox/siterepo/wprismdemo1 diff
cli/wprism demo apply
cli/wprism demo refusal
cli/wprism demo stop
```

The demo checks that the target page matches the captured change and that a
target-only comment survives. It also checks that an attempt to substitute an
untrusted target binding is refused. `stop` removes the disposable sites and
repositories.

Setup stops for explicit review of the page-only contract. The demo runs the
real release authorization preview followed by a bounded evaluation apply.
Production release additionally requires source staging, preparation, signed
authorization, and execution. The
[step-by-step demo](docs/guides/try-wprism.md) explains expected results,
cleanup, and troubleshooting.

## Choose your next step

| I want to… | Start here |
|---|---|
| Try a change on disposable sites | [Try WPrism](docs/guides/try-wprism.md) |
| Find out whether my hosting and site qualify | [Site eligibility](docs/guides/site-eligibility.md) |
| Connect and adopt an existing site | [Quickstart](docs/guides/quickstart.md) |
| Work on an adopted site | [Daily workflow](docs/guides/daily-workflow.md), [release](docs/guides/release.md), [recovery](docs/guides/recovery.md) |
| Report a bug or contribute | [Contributing](CONTRIBUTING.md) |
| Add support for a plugin | [Your first adapter contribution](docs/guides/adapter-authoring.md) |
| Use an AI agent to operate WPrism | [Portable WPrism skill](skills/wprism/) |

`cli/wprism --help` shows the main workflows. Use `cli/wprism help release`
for one command, or `cli/wprism help all` for the full reference.

## What is supported?

Coverage depends on the exact WordPress, PHP, database, plugin versions,
surface, operation, and host capabilities. Render the current reviewed
library directly from its sources:

```sh
php tools/capability-doc.php render
php tools/adapter-grade.php render
```

For an adopted site, use `wprism assess <env>`, `wprism doctor <env>`, and
`wprism capabilities <env>`. Read
[capabilities and limits](docs/guides/capabilities-and-limits.md) for the
meaning of each result.

A `certified` adapter means its surface was declared, reviewed with a written
reason, and exercised by its named tests. It does not mean independent
certification, whole-site support, or evidence sealed to a particular run.
[Capability semantics](docs/capabilities.md) describes that contract.

## How it works

The WordPress drop-in separates authored, portable source state from runtime,
derived, secret, and environment-bound state. It captures authored entities as
canonical text files with stable identities. The CLI orchestrates review,
capture, planning, application, and recovery over local, Docker, or SSH
transports. A hook-free database write phase avoids replaying normal WordPress
write hooks, followed by declared derived-state rebuilding. External effects
and recovery limits are reviewed separately.

| Source | Responsibility |
|---|---|
| `agent/` | WordPress drop-in and state engine |
| `cli/` | Dependency-free host orchestrator |
| `recovery/` | WordPress-independent recovery runtime |
| `adapter-packages/` | Plugin adapters with their own tests, fixtures, and evidence |
| `platform/adapter-library/` | Core policy, profiles, compatibility, and trust roots |
| `sandbox/`, `tools/`, `tests/` | Development and verification infrastructure |

Only the assembled agent and recovery runtime are installed on a managed
site. The [repository-format specification](spec/repo-format.md) is normative;
[DESIGN.md](DESIGN.md) explains the architecture.

## Community and development

Questions and usage examples belong in
[Discussions](https://github.com/samrat-shamim/wprism/discussions); reproducible
bugs and proposed work belong in
[Issues](https://github.com/samrat-shamim/wprism/issues).
[CONTRIBUTING.md](CONTRIBUTING.md) covers a first contribution, setup, and local
validation. No internal tracker access is required.

We welcome documentation improvements, small reproductions, host-eligibility
reports, and bounded adapter contributions. The
[community roadmap](docs/community-roadmap.md) focuses on independent agency
adoption. [Governance](GOVERNANCE.md) explains review and decision-making;
the [code of conduct](CODE_OF_CONDUCT.md) applies to project spaces.

Report vulnerabilities privately using [SECURITY.md](SECURITY.md).
For maintainers, [publication and releases](docs/maintainers/releases.md)
describes how to prepare and verify a release.

## License

[GPL-2.0-or-later](LICENSE). Contributions use the same license and the
[Developer Certificate of Origin sign-off](CONTRIBUTING.md#license-and-sign-off).
