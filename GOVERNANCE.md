# Governance

WPrism is a founder-led open-source project maintained by
[Samrat Shamim](https://github.com/samrat-shamim). The repository's maintainers make
release and merge decisions and are accountable for the supported boundaries
those releases advertise.

## Decisions and participation

Use GitHub Issues for reproducible bugs and proposed work, and Discussions for
usage questions, examples, and early design discussion. Contributions do not
require access to a private tracker or an agent-specific workflow.

Small fixes can start as pull requests. Before substantial architectural work,
new public contracts, or a new adapter's claimed scope, open an issue describing
the user need, proposed boundary, evidence, and migration consequences.
Maintainers record the decision and rationale in the public issue or pull
request. Internal planning may continue separately; it cannot be a prerequisite
for understanding or reviewing a public contribution.

The [community roadmap](docs/community-roadmap.md) explains current priorities.
The [product specification](docs/product-spec.md), [repository specification](spec/repo-format.md),
and [capability model](docs/capabilities.md) retain their existing authority.
A feature request does not change a capability claim.

## Review and authority

Maintainers review changes against the architecture and local evidence in
[AGENTS.md](AGENTS.md). Adapter authors supply declarations, tests, and proposed
dispositions; maintainers review the claimed boundary before accepting it.
Passing tests alone does not authorize production operations or grant a
certification claim.

Public interfaces and identity-bearing adapter changes require explicit
migration discussion. Release notes identify changes that require recompiling,
re-pinning, or renewed assessment. Security reports follow [SECURITY.md](SECURITY.md).

Commit access can grow from sustained, reviewed contributions and dependable
handling of the affected domain. It is granted explicitly by the repository
owners. Maintainers may step back; outstanding work and ownership should be
handed over in public without disclosing private security reports.

## Open-source and commercial work

The project uses GPL-2.0-or-later. Contributions use that license and a DCO
sign-off; no copyright assignment is required.

Paid onboarding, qualification work, training, or support may fund maintenance.
Payment does not replace review, widen supported scope, or grant execution
authority. Improvements proposed for the shared project follow the same
public contribution process. No paid service is required to run the software.

Project spaces follow the [code of conduct](CODE_OF_CONDUCT.md).
