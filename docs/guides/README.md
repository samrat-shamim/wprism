# Task guides

Choose the task you need to complete. A guide gives the order and decisions;
[detailed references](../reference/README.md) hold configuration and contracts.
Use `cli/wprism help <command>` for current syntax and flags.

## Evaluate and operate a site

| Guide | Read it when |
|---|---|
| [Try WPrism](try-wprism.md) | You want a first page change on two disposable sites. |
| [Site eligibility](site-eligibility.md) | You need to check host access and supported scope. |
| [Quickstart](quickstart.md) | You want to connect a site and create its first baseline. |
| [Assessment](assess.md) | You need to record a site's managed boundary as a contract. |
| [Daily workflow](daily-workflow.md) | Your team needs the capture, review, and reconciliation loop. |
| [Release](release.md) | You are staging, authorizing, executing, and verifying a change. |
| [Recovery](recovery.md) | You are rehearsing recovery or handling a failed release. |
| [Code updates](code-updates.md) | You are updating a plugin/theme or resolving a code refusal. |
| [Flag day](flag-day.md) | You are moving a fleet across an agent/spec version change. |
| [Capabilities and limits](capabilities-and-limits.md) | You need to understand an admitted scope or a refusal. |

Read recovery before your first release. A connection, baseline, or successful
demo is evidence for its declared scope; broader operation readiness needs its
own complete assessment.

## Author and extend

| Guide | Read it when |
|---|---|
| [Adapter authoring](adapter-authoring.md) | You want a first contribution or a site-specific adapter. |
| [Coverage cohort](coverage-cohort.md) | You are prioritizing a batch of adapter work and measuring its result. |
| [Trust enrollment](trust-enrollment.md) | You are enrolling, rotating, or revoking an authority key. |
| [Internals](internals.md) | You need to interpret an internal command from a log or receipt. |

[Adapter reference chapters](../reference/README.md#authoring-an-adapter)
cover storage recipes, native actions, validation, dispositions, and signing.
[The generated limitation ledger](adapter-authoring-limitations.md) describes
the current grammar's boundaries.

## Keep guides current

Write shipped behavior in the present tense. Mark an unshipped command at its
citation with the literal `**Planned** — not yet shipped.` label and keep it out
of runnable examples. Link configuration and command contracts instead of
copying a second flag table into a guide.

Run `bash tools/check-docs.sh` after documentation or dispatch changes. It
checks cited host verbs and agent subcommands, then local links and Markdown
anchors. Flags and workflow meaning still require review against built-in help
and product-path evidence. The command check does not infer native readiness
from a valid verb.

Render capability and grade claims from their owners rather than copying a
plugin matrix. Document refusal and recovery limits at the decision that needs
them. [CONTRIBUTING.md](../../CONTRIBUTING.md) describes review and local gates.
