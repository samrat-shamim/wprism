# Security

## Supported versions

WPrism is **pre-1.0 and single-track**. Before the first tagged alpha release,
`main` is the evaluation candidate. Once alpha releases are published, the
latest published alpha is the supported release; older alphas receive no
backports. Fixes land on `main` and ship in a replacement release. Development
commits on `main` are not a stability promise.

Include your exact release tag or commit in a report. Release notes state the
upgrade steps, including any required recompile/re-pin or reassessment. See
[publication and releases](docs/maintainers/releases.md).

WPrism runs with write access to a WordPress database, filesystem and code tree,
and reaches production hosts over SSH. Treat findings in `agent/`, `cli/`,
`recovery/`, `adapter-packages/*/package/`, and `platform/adapter-library/` as
reachable from a real site. Adoption embeds the latter two sources inside the
installed agent.

## Reporting a vulnerability

**Report privately, through GitHub Security Advisories on this repository** —
the *Security* tab → *Report a vulnerability*. That opens a private thread with
the maintainers.

Do **not** open a public issue, pull request, or discussion for a security
finding, and do not include a working exploit against a third party's site.

A useful report includes: what an attacker gains, the smallest reproduction you
have (a failing command, a repository state, a manifest), the commit you saw it
on, and any refusal or error text verbatim — this project's refusal messages are
load-bearing and the exact string usually locates the defect.

## What to expect

- **Acknowledgment** that the report was received and read.
- **A triage answer**: whether it reproduces, and whether it is a vulnerability,
  a correctness bug, or working-as-designed with a boundary that should have
  been documented more loudly.
- **A fix that ships with regression coverage failing against the prior
  defect**, through the product path — the same discipline every other change in
  this repository is held to (see [`AGENTS.md`](AGENTS.md), non-negotiable 9).
- **Credit** in the advisory if you want it, and none if you do not.

No response-time or fix-time commitment is offered. This is a pre-1.0 project
with no security SLA, and stating one that could not be honoured would be worse
than stating none.
