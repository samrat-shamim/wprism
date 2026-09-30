# WPrism guides

The reference pages answer "what does this flag do". These guides answer "what
do I run, in what order, and what do I do when WPrism refuses". They are narrative
and task-shaped, and they **link** the reference pages rather than restating
them — a duplicated flag table is a flag table that goes stale in silence.

## Who each guide is for

| Guide | Read it when |
|---|---|
| [try-wprism.md](try-wprism.md) | You want to try a page change on disposable sites first. |
| [site-eligibility.md](site-eligibility.md) | You need to check hosting, access, and compatibility before adoption. |
| [quickstart.md](quickstart.md) | You have a WordPress site (or an empty repo) and no WPrism yet. |
| [assess.md](assess.md) | You need to know what WPrism can honestly do with a site, and to write that decision down as a contract. |
| [daily-workflow.md](daily-workflow.md) | WPrism is installed and your team needs a day-to-day loop. |
| [release.md](release.md) | You are shipping a change: rehearse, read the authorization plan, release, verify. |
| [recovery.md](recovery.md) | A release failed, or you want to know exactly what a rollback would and would not give back. |
| [flag-day.md](flag-day.md) | You are moving a fleet across an agent/spec bump, or deciding whether you can still roll one back. |
| [code-updates.md](code-updates.md) | You are updating plugin/theme code, or a code refusal is blocking you. |
| [adapter-authoring.md](adapter-authoring.md) | A plugin your site depends on has no manifest, or an existing one is short. |
| [first-adapter.md](first-adapter.md) | You want to make a small, reviewable adapter contribution. |
| [coverage-cohort.md](coverage-cohort.md) | You are choosing which adapters to build next, and you want the batch graded on whether coverage actually moved. |
| [trust-enrollment.md](trust-enrollment.md) | You are enrolling a signing key in the platform trust root, rotating one, or burning one after a compromise. |
| [capabilities-and-limits.md](capabilities-and-limits.md) | You need to know what WPrism will and will not manage, and why a plan is red. |
| [internals.md](internals.md) | You saw a `wp wprism` command in a log or a receipt and want to know what drives it. You should not be typing these. |

Reading order for someone new: **try-wprism → site-eligibility → quickstart → assess → daily-workflow →
release → capabilities-and-limits**, then **recovery** before your first
production release rather than during it, then **code-updates** the first time
you ship a plugin update, then **adapter-authoring** the first time you hit a
plugin nobody has written a manifest for, and **coverage-cohort** the first time
you are authoring more than one adapter and have to decide which ones.
**internals** is reference, not reading.

## The honesty contract

These guides describe only what exists at the commit that publishes them.

- **Shipped behavior is stated in the present tense** and every command, verb,
  and flag in these pages was read out of `cli/wprism` or `agent/src/Command/Cli.php`
  before it was written down. `sandbox/tests/spike/check_guide_commands.sh` re-proves
  that mechanically: it extracts every `wprism <verb>` and `wp wprism <command>`
  token from these files and fails, naming the guide and line, if the token is
  not a real dispatch entry.
- **Unshipped behavior is labeled inline**, at the exact sentence that mentions
  it, as `**Planned** — not yet shipped.` It is never written in the
  present tense and never demonstrated in a runnable code block. The checker
  enforces the other half of that rule: a command that does not exist is a hard
  failure *unless* the line carrying it also carries that literal label.
- **Derived claims are rendered, never copied.**
  [../capabilities.md](../capabilities.md) explains the model; `php
  tools/capability-doc.php render` prints the exact matrix from capsule-owned
  manifests and dispositions. `make release-gate` validates that source set
  without checking in an aggregate adapter inventory. No guide restates a row
  of the matrix; a stale hand-copy of a capability claim is worse than no
  claim.
- **A boundary is documentation too.** Where WPrism cannot do something, these
  guides say so plainly rather than routing around it. "No command does this
  today" is a supported answer, and it appears in these pages several times.

## Where the reference lives

- [cli/README.md](../../cli/README.md) — the orchestrator: every verb, every
  flag, the environment registry, transports, env-bound provisioning.
- [spec/repo-format.md](../../spec/repo-format.md) — the site-repo and manifest
  format, normative.
- [docs/adoption.md](../adoption.md) — the SSH adoption contract in full.
- [docs/capabilities.md](../capabilities.md) — capability semantics and the command that renders the current matrix.
- [DESIGN.md](../../DESIGN.md) — why any of this is shaped the way it is.
