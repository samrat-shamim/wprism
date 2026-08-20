# Duo — product specification

*Status: **RATIFIED — founder decision** (2026-08-14).*

This document defines Duo's durable product direction: the customer promise,
product boundary, safety model, core workflows, and strategic priorities. It is
not a source of current compatibility claims or wire-format behavior.

The document hierarchy is:

- [DESIGN.md](../DESIGN.md) is the founding design record and safety rationale.
- [spec/repo-format.md](../spec/repo-format.md) is the normative repository and
  artifact contract.
- [docs/capabilities.md](capabilities.md) is the generated, exclusive source of
  current platform-certified capability claims.
- [docs/roadmap.md](roadmap.md) records current sequencing, shipped status, and
  owner rulings.
- This document owns the product promise and direction.

Current platform-certified behavior is authoritative only from the generated
capability document and repository-format specification. Site-certified
behavior is authoritative only from the generated per-site capability
projection described below, backed by current reviewed evidence, a
certification attestation, and environment bindings. Product prose never
creates either kind of capability claim.

Normative convention: **must** marks a condition for claiming the corresponding
product capability; **should** marks the intended default; **may** marks
optional behavior. Unless explicitly labeled Current, present-tense product
behavior describes the target product, not shipped availability. Current
availability is stated only by the generated capability document
([docs/capabilities.md](capabilities.md)) and the roadmap.

This specification supersedes the two 2026-08-13 agent-era drafts and the RC1
working draft. It is distilled from those drafts and the founding design. The
DUO-3332 operator walkthrough informs its usability requirements, but is
product-design evidence rather than external market validation.

## Product definition

> **Duo makes the proven parts of a WordPress site branchable like code.**
> Teams and their agents can branch, rehearse, review, merge, and release code
> plus authored content and configuration while orders, inventory, sessions,
> secrets, and other live or environment-bound data remain local.

Assessment is Duo's default entry action, not a completeness claim. For any
reachable WordPress site, Duo returns either a bounded assessment of facts
observable with the granted access or a structured refusal naming missing
access, unsupported topology or platform, and the next safe action. The result
distinguishes assessed, access-limited, unsupported, and unobserved scope. Only
evidence-backed capabilities become branchable; adoption never implies
universal support or reversibility.

Duo is the **agent-native change and release layer for WordPress**. Branchable
WordPress is the market wedge. The underlying versionability and evidence
system is how that wedge expands safely across WordPress's long tail.

### What versionable means

For an operation that moves or restores state, a surface is versionable when
Duo can prove that it can:

1. distinguish authored intent from runtime, derived, secret,
   environment-bound, and externally owned state;
2. represent the authored state with stable identity in a reviewable repository;
3. materialize it in another environment without corrupting references or
   overwriting protected live state;
4. rebuild or verify affected derived behavior;
5. expose external effects and recovery limitations before authorization; and
6. detect when changes to the application or environment invalidate the proof.

Assess can describe a surface without asserting that it is versionable.

Versionability is never a whole-site assertion. It is a capability for a
particular stack, surface, operation, environment, and consequence boundary.

## The problem

WordPress lets people assemble applications from core, themes, plugins, custom
code, database content, configuration, uploads, and external services. The
result is powerful, but its state has no native boundary between what should
move with a feature and what must remain live.

Code can be branched. The database usually cannot. A staging database can be
cloned, but copying it back risks destroying orders, inventory changes, form
entries, memberships, comments, sessions, scheduled work, or environment
configuration. Avoiding the copy leaves authored content and plugin
configuration stranded in staging. Long-running branches therefore require
manual reconstruction, content freezes, risky database work, or lost changes.

Agents make this problem more urgent. They make changes and parallel iteration
cheap, but they do not make ambiguous state, hidden references, irreversible
effects, or weak recovery safe. Agent confidence cannot substitute for a
deployment contract and executable evidence.

## Initial customer and participants

### Initial commercial wedge

Duo initially serves **WordPress agencies and professional development teams
operating plugin-heavy, revenue-bearing sites**, especially WooCommerce sites.
They already use Git, staging, SSH or comparable environment access, and are
adopting agents for development and operations.

This customer has:

- recurring need to change live sites without losing business activity;
- enough technical authority to install and operate Duo;
- multiple sites over which qualification knowledge and operating discipline
  compound;
- a buyer who understands the cost of manual database reconciliation; and
- an immediate use for read-only assessment, isolated rehearsal, and safe
  merge/release workflows.

**Initial sellable outcome.** On an eligible single-site WooCommerce
installation, an agency operator can place a non-destructive change to
platform-certified storefront content, product catalog data excluding
operational inventory, media, and supported configuration on a Git branch;
rehearse it in isolation; Merge it; and Release it without overwriting orders,
sessions, stock reservations, secrets, or environment bindings. If the change
requires an unqualified surface, destructive migration, or uncontained live
effect, Duo stops at assessment or qualification without promising release.
The subscriptions scenario below is a destination outcome, not an initial
availability claim.

**Initial eligibility.** Assessment requires authorized filesystem and database
read access. Rehearsal additionally requires an operator- or host-supplied
snapshot and disposable-environment provider. Production release requires
code-deployment authority, target fencing, environment bindings, and the
authority required by the selected recovery profile. Duo validates and
orchestrates configured providers; it does not supply hosting or compute.
Missing authority leaves stronger workflows unavailable and appears as an
explicit assessment result.

**Commercial hypothesis.** The initial economic buyer is an agency owner or
engineering lead accountable for releases across a portfolio of revenue-bearing
sites. A senior WordPress developer is the operator; the client owner approves
business consequences. In-house development teams are design partners rather
than a second launch segment. The initial packaging hypothesis is per adopted
production site, with assessment and a bounded qualification allowance
included; Phase A validates willingness to pay, qualification cost, and
gross-margin bounds before pricing is fixed.

### Product participants

- **Developer or agency operator:** configures the application contract,
  reviews technical boundaries, and owns releases across one site or a fleet.
- **Site or business owner:** states desired outcomes, reviews visible changes,
  and authorizes business ownership, external effects, downtime, and risk.
- **AI agent:** operates Duo's detailed interfaces, performs discovery and
  experiments, proposes contract changes, gathers evidence, and explains
  decisions in WordPress language.
- **Host or platform team:** supplies environment authority such as snapshots,
  traffic exclusion, code releases, secret storage, retention, and recovery.

Duo is **agent-native but not agent-dependent**. It does not initially ship or
manage a general-purpose agent. It ships stable contracts that any capable
agent can drive, plus replaceable operating guidance, an agent skill, examples,
and evaluation scenarios that make correct operation reproducible.

## Canonical user outcome

A user should be able to ask:

> Add subscriptions, update the store design, test the customer journey, and
> publish it without losing today's orders.

The resulting experience should:

1. connect to the existing site with read-only authority first;
2. explain what is already safely versionable and what remains unknown;
3. create or select an isolated preview environment;
4. let the user's agent implement code, authored-state, and contract changes;
5. attempt to qualify any required unknown surface in disposable environments,
   or explain why it remains unavailable;
6. present a concise review in terms of products, pages, settings, plugins,
   integrations, and affected journeys;
7. ask only for consequential decisions that policy has not already authorized;
8. release within the strongest capability and recovery profile the target can
   prove;
9. verify the affected behavior after release; and
10. retain enough evidence for audit, retry, recovery, and requalification.

UUIDs, ledgers, policy projections, evidence digests, effect inventories,
receipts, and provider capabilities remain available to agents and experts but
are not ordinary user vocabulary.

## Product principles

1. **Branchability is the wedge.** Assessment, qualification, recovery, and
   evidence exist to make code plus authored WordPress state safely branchable;
   Duo does not become a generic agent-governance platform.
2. **Loud, blocking, scoped.** Unknown or unproved behavior never silently
   enters the repository, silently disappears from it, or inherits a broader
   Ready claim. A precise refusal is better than an approximate success.
3. **Plugins and themes run unmodified.** Versionability cannot require the
   WordPress ecosystem to adopt Duo-specific storage APIs.
4. **Authored intent moves; live truth stays local.** Human-authored, portable,
   source state is branchable. Runtime, derived, environment-bound, secret, and
   externally owned state is retained, rebuilt, rebound, or re-synchronized
   according to explicit policy.
5. **Evidence over confidence.** Agent-generated rules, adapters, oracles, and
   providers remain Uncertified proposals until the required evidence, review,
   and certification attestation exist. Passing tests does not grant
   certification or execution authority, and a successful command or clean byte
   round trip is not by itself a successful business outcome.
6. **Narrow, composable guarantees.** Support is stated per surface and
   operation. A site may safely merge products while product deletion or live
   payment rollback remains unsupported.
7. **One machine contract, two views.** Agent interfaces are structured,
   non-interactive, stable, and remediable. Human views are semantic projections
   of the same decision, never a parallel workflow with different safety
   conclusions.
8. **Human authority is reserved for intent and consequence.** Agents perform
   discovery, fixtures, tests, diffs, retries, and evidence collection. People
   or explicit organizational policy decide ownership, sensitive data,
   destructive effects, editorial conflicts, downtime, and acceptable risk.
9. **Refusal is a productive state.** Every refusal identifies the requested
   business operation, the missing proof, and valid next actions: qualify,
   isolate, sandbox, exclude, or change scope. A human may authorize a known,
   bounded irreversible effect or weaker recovery profile; an unknown effect
   capable of reaching a live system always blocks.
10. **Recovery claims are literal.** Rollback names exactly which resources and
    effects it restores. It never implies that emails, payments, webhooks, or
    other external reality were undone.
11. **Adoption is progressive.** Every step must deliver value without implying
    a site-wide maturity level. There is no universal "Duo-ready" badge.

## The versionability contract

Duo evaluates a capability equivalent to:

```text
capability(
  application_stack,       # code, dependency versions, schema fingerprints
  surface,                 # authored/generated state or external effect
  operation,               # customer operation defined below
  environment_profile,     # target capabilities, bindings, infrastructure
  effect_binding,          # prevented, sandboxed, live, unknown
  recovery_profile         # what failure can provably restore
) -> {
  state_class,
  handling,
  technical_readiness,
  certification_provenance,
  effect_containment,
  effect_recovery_semantics,
  conditions,
  expiry_and_dependencies,
  remediation
}
```

This spec uses the customer operations **Assess, Rehearse, Capture, Merge,
Release, Verify, Delete, and Recover**. Branching is a Git workflow outcome. The
per-operation capability projection may decompose one customer operation into
engine operations such as compile, plan, deploy, apply, promote, recapture, and
render-api — that projection is what `AdapterRegistry::report()` evaluates for
an exact operation × surface × target and what `duo capabilities` returns. A
customer operation is technically Ready only when every engine capability on
its declared dependency map is current; product terminology cannot create an
alias claim.

Assess may run under its bounded read authority regardless of the readiness of
the capabilities being evaluated. Rehearse may use Experimental or Uncertified
capabilities only in an approved disposable environment after containment is
proven. Capture, Merge, Release, Delete, and Recover operations that mutate
portable or production state require every dependency to be Ready or Ready with
conditions whose conditions pass, Platform-certified or Site-certified, and
separately authorized against the frozen plan. Verify inherits the authority
and effect boundary of the operation it verifies. Uncertified capability never
authorizes production. A failing dependency blocks only operations that require
it; Ready never implies whole-plugin or whole-site safety.

### Certification provenance

Certification provenance and technical readiness are separate dimensions:

- **Platform-certified:** reusable, centrally reviewed evidence covers the
  exact declared stack, surface, and operation. The generated capability
  document is the sole source of these claims.
- **Site-certified:** this application's reviewed contract, site-bound evidence,
  and certification attestation cover the exact capability. Site-certified
  means approved by a named customer organization under its policy through
  Duo's certification protocol; it is not a Duo platform-team endorsement and
  is never silently reused on another site.
- **Uncertified:** no current platform or site attestation covers the exact
  capability. Experimental evidence may exist, but it cannot authorize a
  production operation.

The platform registry is the platform-certified baseline catalog and seed
corpus, not the permanent ceiling. Site certification expands coverage without
weakening or impersonating the platform claim. Every site-certified projection
exposes the approving principal, policy version, attestation, evidence digest,
dependency set, and expiry.

### Technical readiness

- **Ready:** all required technical evidence is current for the requested
  operation. Execution authority is evaluated separately.
- **Ready with conditions:** required technical evidence is current, but
  execution is permitted only when every named, machine-checkable condition is
  satisfied and rechecked at the mutation gate. An unmet or uncheckable
  condition blocks.
- **Requalification required:** a previously supported capability has an
  expired or changed dependency.
- **Experimental:** evidence permits only disposable or explicitly
  non-production use; it can never authorize production, including through a
  conditional path.
- **Not qualified:** semantics may be proposed or partly understood, but the
  required proof is incomplete.
- **Unsupported:** Duo understands the boundary well enough to refuse the
  operation for a stated reason.

### State class and handling

State class preserves the founding partition: **authored, runtime, derived,
environment-bound, external, or unclassified**. Handling is stated separately:
**manage, preserve local, rebuild, rebind, re-synchronize, or block**. This
prevents environment-local data, regenerated indexes, secrets, and ERP-owned
catalog fields from collapsing into one ambiguous "excluded" status.

Unclassified ownership or semantics always uses **block**. It queues loudly and
cannot silently enter or skip the portable application contract.

Secret and PII are orthogonal sensitivity findings, not state classes. They are
evaluated through the normative sensitivity gates and authorization scope, and
projections expose their status without exposing values.

### External-effect containment and recovery semantics

Every relevant external effect has two separate declarations:

- **Containment:** prevented, sandboxed, live, or unknown.
- **Recovery semantics:** not applicable, provider-state restorable,
  compensatable, irreversible, or unknown.

Provider-state restorable means Duo can restore an exact, named remote resource;
it never claims to undo observation or downstream action. Compensatable means a
separate action such as a refund may offset part of the consequence without
reversing the original effect. Unknown containment or recovery semantics blocks
any operation capable of reaching a live system. A known, bounded live effect
may proceed only with plan-bound authority and is never disguised inside a
generic success or rollback status.

### Authorization

Technical readiness and reusable evidence never carry execution authority.
Immediately before mutation, Duo evaluates authorization against the frozen
plan, authenticated actor, target, state handling, effect inventory, recovery
profile, conditions, and expiry. Any plan change invalidates that authorization.

### User-facing projection

The detailed contract is projected in WordPress and business language. This
table illustrates the projection; it is not a current capability claim.

| Surface | Operation | State class | Handling | Readiness | Certification | Containment | Recovery semantics | Meaning |
|---|---|---|---|---|---|---|---|---|
| Products and media | Merge and Release | Authored | Manage | Ready | Platform-certified | Prevented | Not applicable | Proven on the exact stack and target |
| Orders and inventory | Capture from preview | Runtime | Preserve local | Unsupported | Platform-certified | Prevented | Not applicable | Live operational state is not copied |
| Payment checkout | Rehearse | External | Rebind | Ready with conditions | Site-certified | Sandboxed | Not applicable | Uses a sandbox identity; no live charges |
| Live payment effects | Recover | External | Block | Unsupported | Uncertified | Live | Compensatable | A refund cannot undo observation or downstream action |
| Custom catalog table | Capture and Release | Unclassified | Block | Not qualified | Uncertified | Unknown | Unknown | The agent can attempt bounded qualification |

## The application contract

Each adopted site has an **application contract**, internally referred to as
the application compatibility capsule. It is a logical product object composed
of five deliberately separate parts.

### 1. Reviewed declarations

Versioned in the site repository:

- exact stack and schema requirements;
- state classes, handling, ownership, stable identities, and reference paths;
- custom-table and generated-state declarations;
- rebuild actions and semantic oracles;
- external-effect and recovery requirements;
- evidence expiry and invalidation dependencies; and
- explicit unsupported boundaries.

Declarations are reviewable inputs. They cannot certify themselves.

### 2. Generated evidence

Qualification produces evidence by exercise. Each declared capability names the
conformance suites that cover it, and those suites run against a live pair of
environments on the exact stack, schema, and bindings in front of them,
observing authored state, references, protected runtime, and rebuilt behavior.
A capability is evidenced when its named suites pass there. The evidence is a
current run rather than a stored record: it is repeated against the environment
that will rely on it, so it cannot outlive the code, schema, or declarations it
was produced against. A passing suite proves behavior, not authority. The
repository pins the reviewed declarations, the exact adapter bytes those suites
ran against, and the requested capability bindings; effective readiness is
always recomputed and cannot be asserted by repository text.

### 3. Certification attestation

Certification is a signature by a named approving principal over the exact bytes
it approves. The approving key belongs to a configured platform or
customer-organization trust root, the signature covers the declaration's exact
content, and the repository pins that identity and digest; verification runs on
every load, so a changed declaration, certificate, authority record, or pin
drops the claim rather than degrading it quietly. Platform certification takes
the same shape with the platform as approver: a reviewed disposition, written
with its reason and held where no declaration can reach it, which the generated
capability document then projects. Either path records what it attests to —
which validators accepted the declaration, whether the capability was exercised,
and the approver's written reason — so approval is never read as proof of a run.
The agent that proposed the declaration or ran the tests cannot confer
certification merely by editing the repository or declaring its work successful.
The attestation names the approving principal and whether the claim is platform-
or site-certified.

### 4. Environment bindings

Credentials, URLs, paths, sandbox/live external identities, retention policy,
provider authority, and secret values remain outside the portable application
contract. The repository may declare requirements and binding names, never
secret values.

### 5. Generated projection

Duo composes platform defaults, reviewed site declarations, current evidence,
certification attestations, and environment bindings into one capability view
consumed by assessment, planning, promotion, recovery, agents, and human
review.

### Extension boundary

Application-specific behavior stays outside generic engine decision logic.
Initial extensions are declarative wherever possible. Executable interpreters,
providers, or oracles run only inside explicit version, trust, capability, and
evidence boundaries. Duo does not initially expose a general-purpose agent code
execution SDK.

An application contract may narrow a platform default or fill an extensible
gap through a registered extension point with stronger site-specific evidence.
It may never override engine invariants, permanent non-goals, closed grammar,
or a platform safety boundary explicitly marked non-overridable, and it may
never silently weaken secret, PII, deletion, mutation, external-effect, or
recovery guards. The registry must distinguish extensible gaps from
non-overridable safety boundaries. Any conflict fails closed.

## Core product workflows

The adoption journey has four user-facing stages. Capability qualification is
cross-cutting rather than a late maturity rung, and recovery is a release
profile rather than a site-wide level.

### 1. Assess

Assessment is the default entry point for a reachable site. It uses declared
read-only application, database, and filesystem authority and invokes no known
semantic mutation or live external-service effect. Unavoidable infrastructure
access and audit logging must be disclosed in the environment profile. If
access, topology, or platform support is insufficient, the assessment result is
a structured, scoped refusal rather than a partial success presented as
complete.

Duo must:

- inventory the stack, storage surfaces, integrations, and environment
  authority;
- show each observed surface's state class, handling, technical readiness,
  certification provenance, effect containment, and effect recovery semantics;
- explain why each consequential conclusion was reached;
- disclose the access or instrumentation needed for deeper observation;
- produce a proposed application contract without granting it authority; and
- name the smallest safe next action for each gap.

The default output emphasizes products, pages, forms, menus, plugins, orders,
and affected workflows. Raw storage detail is available only inside the
authorized data-sensitivity and egress scope, with secret and PII values
redacted by default.

### 2. Rehearse

Duo addresses an operator- or host-provisioned disposable environment
initialized from an approved snapshot. It may orchestrate declared provider
capabilities, but does not itself promise infrastructure provisioning. Agents
can implement and test there without production mutation authority.

Rehearsal must:

- minimize snapshot data for the requested test; encrypt it in transit and at
  rest; isolate access; redact logs; and enforce declared retention and secure
  deletion;
- strip or rebind production credentials before the environment boots;
  default-deny outbound HTTP, mail, payment, webhook, and queue destinations;
  allow only declared sandbox destinations after their bindings are verified;
  and verify containment before exercising workflows;
- make target-local URLs, identities, paths, and synthetic environment bindings
  deliberately different where that helps expose hidden coupling;
- expose the exact code, authored state, generated state, and effects a release
  would touch;
- support repeatable teardown and retry; and
- preserve evidence useful for qualification and later review.

### 3. Version and merge

Proven authored state enters the repository alongside code and media.

Duo must:

- capture only authored state whose handling and operation are technically
  Ready and separately authorized;
- apply the normative secret and PII gates from
  [spec/repo-format.md](../spec/repo-format.md); secret-pattern exceptions permit
  only reviewed false positives, while PII exceptions remain explicitly scoped
  under the normative contract; environment-bound secret values never enter
  portable state;
- block unknown references and unclassified writes;
- produce deterministic, reviewable representations with stable identity;
- preserve environment-local runtime state;
- support ordinary Git branching and three-way merge;
- present genuine conflicts by WordPress identity, affected field, URL, and
  business consequence; and
- make capture-first reconciliation the default when a target has drifted.

### 4. Release and verify

Release coordinates code, authored state, media, lifecycle work, generated
state, external effects, and recovery under one plan.

Before any production-visible code, data, filesystem, lifecycle, or
external-effect mutation, Duo must durably bind and present an authorization
plan showing:

- the exact requested scope;
- every capability and condition the release depends on;
- what code, authored state, runtime-adjacent state, and external systems may
  change;
- the selected recovery profile and what it does not restore;
- known irreversible effects and any unknown effect that blocks release; and
- any authority still required.

Every production plan declares **verified automatic**, **operator-directed**,
or **none** as its recovery profile, together with covered resources, writer
exclusion, and maximum loss boundary. `none`, or a profile weaker than policy,
requires plan-bound authority; absence of a profile is never implicit approval.
The target-fresh checkpoint and provider preflight required by the selected
profile must pass before mutation.

Release then uses the established deploy-before-apply ordering, target fencing,
drift checks, and recovery profile. Success requires post-release verification
of affected journeys, not only successful commands. Failure returns a
documented public next action: resume, reconcile, retry, recover, requalify, or
escalate. Retry uses the same operation identity and is offered only after
durable receipt reconciliation proves replay safe. Ambiguous commitment is
quarantined as reconcile-required; it is never retried generically. If no
automated action is safe, Duo refuses and identifies the required operator
authority. Normal operation never depends on private identifiers, undocumented
commands, or raw database surgery.

### Day-two operation

On Assess, status, plan, and immediately before mutation, Duo must report
relevant application, environment, and evidence drift. Optional monitoring may
surface drift earlier but is not required for the core contract. Changes made
outside Duo are adoptable where safe. Dependency changes invalidate bounded
capabilities rather than the whole site. Preview expiry, artifact retention,
cleanup, receipt reconciliation, and recovery are ownership-aware and
idempotent.

## Qualification and requalification

Qualification supplies site-bound evidence for a requested capability. It may
change certification provenance to Site-certified and technical readiness from
Not qualified, Experimental, or Requalification required to Ready or Ready with
conditions. It does not by itself change state class or handling, grant
authority, or guarantee that qualification will succeed.

The first-class workflow is resumable and performs the following as required by
the requested operation:

1. fingerprint code, dependencies, schemas, configuration, integrations, and
   environment authority;
2. address operator- or host-provisioned disposable source and target
   environments using least-data approved snapshots under the Rehearsal data,
   isolation, retention, and egress contract;
3. before booting application workflows, strip or rebind production
   credentials; install sandbox identities; default-deny outbound HTTP, mail,
   payment, webhook, and queue destinations; allow only declared sandbox
   destinations after their bindings are verified; and verify containment. If
   containment is not proven, stop qualification;
4. exercise representative administrator, editor, customer, cron, queue, and
   integration workflows while observing database, filesystem, HTTP, mail,
   cache, and scheduler effects;
5. compare observations with platform defaults and propose the smallest
   application-contract extension;
6. ask bounded questions where business ownership or acceptable consequence is
   ambiguous;
7. run Capture, materialize, Merge, and recapture tests across deliberately
   different local IDs, users, URLs, paths, and environment bindings;
8. assert that protected runtime data remains unchanged;
9. exercise semantic behavior through rendered pages, APIs, plugin-specific
   oracles, and business invariants;
10. test external-effect prevention, sandboxing, or a simulated bounded
    irreversible boundary without invoking a live irreversible effect merely to
    qualify it;
11. inject relevant lifecycle, process, network, and provider failures and test
    retry or recovery for the requested profile; and
12. bind passing evidence to its exact dependencies, submit the reviewed
    declaration to a named approving principal when certification is requested,
    and report state class, handling, readiness, certification provenance,
    effect containment, and effect recovery semantics separately.
    Every unproved dependency remains Unclassified, Experimental, Not qualified,
    or Unsupported as applicable.

### Evidence profiles

Evidence is priced by consequence rather than by one universal checklist:

- **Classification:** combines platform knowledge, runtime observation,
  provenance, value behavior, secret/PII scanning, and explicit authority where
  ownership is ambiguous. No heuristic is authority by itself.
- **Capture and merge:** require deterministic differential round trips,
  identity/reference tests, conflict behavior, and protected-runtime
  invariants.
- **Release:** additionally requires exact target compatibility, rebuild oracles,
  side-effect boundaries, drift handling, and affected-journey verification.
- **Delete or destructive migration:** requires closed reference guards,
  consequence-specific tests, recovery evidence where claimed, and explicit
  authority.
- **Verified recovery:** requires failure injection, exact restore verification,
  appropriate writer exclusion, and coverage of every resource and effect named
  by the recovery claim.

A value that did not change during observation is not certified merely because
it was quiet. It needs applicable platform evidence, an exercise that mutates
and verifies it, or explicit authority paired with a deliberately weaker state.

Round-trip equality is necessary but not sufficient. It can miss byte-stable
runtime data, incorrect hidden references, stale generated state, and broken
business behavior.

### Invalidation

Every capability declares the code, version, schema, policy, oracle,
environment, and provider facts on which it depends, and Duo rechecks those
facts against the target itself at each use. Relevant drift changes a previous
Ready result to **Requalification required**: a version outside a declared
range, adapter bytes that no longer match their pin, a changed reviewed
disposition, or a named suite that no longer passes on the target. Duo
invalidates the smallest dependency-bound capability it can prove; it never
silently assumes continued compatibility.

## Agent and human authority

### Agents may act autonomously when

Agents may act autonomously only inside already granted resource, data,
environment, budget, destination, and effect scopes, and when:

- the action uses declared read-only application, database, and filesystem
  authority, invokes no known semantic mutation or live external-service
  effect, and is confined to authorized resources and data classes; unavoidable
  infrastructure access logging is disclosed, and reads that expose secrets,
  PII, restricted customer content, or restricted external systems still
  require explicit authority;
- experimentation is confined to an approved disposable environment whose live
  external effects are proven prevented or sandboxed;
- the agent is proposing declarations rather than granting them authority;
- it is generating fixtures, plans, diffs, or evidence;
- it is narrowing a capability to a safer or blocking state;
- it is retrying with the same operation identity after receipt reconciliation
  has proved replay safe; or
- production execution is already covered by explicit, current user or
  organizational policy, and every required capability is Ready or Ready with
  conditions whose conditions are machine-verifiably satisfied and covered by
  that policy.

### Human or organizational-policy authority is required to

- decide which system owns an ambiguous business field;
- change a state class or handling decision in a way that could omit intent,
  disclose data, or overwrite live activity;
- access or provision secrets or PII, or approve a sensitivity exception
  permitted by the normative contract; no authority may place an
  environment-bound secret value in portable state;
- enable live external effects;
- accept deletion, loss, or irreversible migration risk;
- resolve a semantic or editorial conflict;
- take production traffic or writers offline;
- choose `none` or a weaker recovery profile than policy requires; or
- accept a known, bounded irreversible external-effect boundary.

An unknown effect capable of reaching a live system cannot be accepted by
human approval or policy; it must be qualified, prevented, sandboxed, or kept
outside the operation.

An agent may recommend an override and prove its mechanics. It may not create
its own authority by editing the policy or capability declaration it is trying
to satisfy.

### Fleet reuse

An organization may reuse an approved semantic policy when its scope, plugin
version range, contract fragment, and intended ownership decision match
exactly. Each site still proves its site-specific stack, schema, environment,
bindings, and behavior. Reusing an acknowledgment is not the same as reusing
certification. Secret, PII, deletion, and loss-risk acceptance is per site by
default.

## Product boundary

### Target product includes

- A host-agnostic CLI, capture/apply agent, repository contract, and immutable
  artifact workflow.
- Assessment, planning, capture, merge, deployment, state application,
  verification, and public recovery orchestration.
- A platform-certified registry of exact WordPress and plugin capabilities.
- Site-specific application contracts and evidence-bound certification.
- Structured interfaces, stable refusal codes, and semantic human projections
  designed for agent operation.
- A replaceable reference operating pack for agents and operators.

### Not included initially

- A general-purpose AI agent or ownership of the conversational interface.
- Hosting or infrastructure provisioning.
- A general arbitrary-code SDK for agent-generated extensions.
- Automatic publication of site evidence to a shared registry.
- A promise that every host supports verified automatic recovery.

### Permanent non-goals

- Multi-master replication between independently authored production sites.
- Versioning orders, sessions, comments, analytics, form submissions, or other
  operational histories as authored state.
- Requiring plugin or theme authors to modify their products for Duo.
- Making every WordPress database row Git-managed.
- Inferring business ownership of ambiguous data without authority.
- Claiming that rollback reverses external reality.
- Quiet best-effort support for an unknown plugin or surface.

Multisite and additional transports may be future scopes, but no prose claim
exists until their exact capabilities are declared in an adapter manifest,
reviewed into `manifests/dispositions.json`, and projected into the generated
capability document.

## Privacy and ecosystem evidence

Duo does not upload site-scoped evidence by default. Initial application
contracts and evidence are local or stored in explicitly configured customer
infrastructure. Configured remote storage and bring-your-own agents are separate
egress paths whose destinations, data classes, redaction, credentials,
retention, and audit requirements must be declared before use.

An ecosystem evidence service is a later product option, not an initial
requirement. It requires explicit opt-in, revocation behavior, threat modeling,
and a privacy review covering content, PII, secrets, low-entropy hashes,
fingerprints, and target-local identity. "Digests only" is not by itself a
privacy guarantee.

Independent field evidence may eventually help promote recurring site-specific
knowledge into platform defaults, but evidence count never substitutes for
semantic coverage, independence, version binding, test quality, and central
review.

## Strategic sequence

Implementation status remains in the roadmap. Product work should proceed in
this order:

### Phase A — Field grounding and adoptability

- Run the complete workflow on multiple third-party agency sites using the
  then-current documented public surfaces.
- Turn the transcripts into a decision-first assessment and operator journey.
- Remove internal-ID, undocumented-command, raw-recovery, and unbounded-output
  leaks that prevent an agent from completing a first session cleanly.
- Establish baseline activation, qualification-cost, and human-attention
  measurements.

Phase A uses a calibration cohort of at least three sites across two agencies,
followed by an independent validation cohort of at least six sites across three
other agencies. Both include transactionally active single-site WooCommerce
installations, materially different plugin stacks, and at least one initially
unqualified requested surface per site. After calibration and before
validation, the founders freeze the remaining cohort inclusion rules and
numeric thresholds for assessment time, preview time, operator interventions,
qualification time and compute, requalification effort, initial-outcome
coverage, willingness to pay, and gross-margin bounds. Failure on the held-out
cohort narrows the ICP or sellable outcome, or stops launch; thresholds do not
move after results are known.

### Phase B — Site certification

- Ship the composed application contract and resumable qualification workflow.
- Separate certification provenance, technical readiness, state class,
  handling, effect containment, effect recovery semantics, and authorization in
  every product surface.
- Bind site evidence to exact dependencies and expose a user-language
  capability projection.
- Require semantic evidence before site-certified capabilities become Ready.

**Phase B launch gate:** every site-certified claim declares its dependencies
and automatically becomes Requalification required on any declared mismatch
before it can authorize an operation. A site-certified capability cannot
authorize production Release unless its external-effect and recovery
requirements are already Ready or Ready with conditions whose conditions pass
at the mutation gate. The independent validation cohort must meet the frozen
launch thresholds and every safety invariant.

### Phase C — Safe scale

- Improve invalidation precision and automate bounded requalification.
- Add reusable fleet policy without confusing authorization reuse with evidence
  reuse.
- Expand external-effect interception, sandbox profiles, and recovery
  verification.
- Continue improving conflict, plan, verification, refusal, and recovery UX.

### Phase D — Ecosystem compounding

- Evaluate consented evidence aggregation only after customer demand and the
  privacy model are proven.
- Promote recurring site knowledge to platform defaults only through the same
  reviewed, evidence-bound release discipline as existing certified claims.
- Add host and agent-platform partnerships after the direct agency workflow is
  repeatable.

## Success measures

### North-star outcome

**Verified qualified releases per active adopted production site per rolling
30 days.**

An **active adopted production site** has completed an assessment within the
measurement window and has at least one current Ready capability. A **verified
qualified release** completed the frozen authorized plan without manual
database reconciliation or unsupported override, preserved protected runtime,
and passed every declared affected-journey oracle. Failed or rolled-back
attempts remain visible but do not count as successful releases.

### Activation and user outcomes

- Time from connection to a useful read-only assessment.
- Time from assessment to the first isolated preview.
- Share of normal workflows completed without internal identifiers,
  undocumented commands, or raw database intervention.
- Number and type of human decisions required per qualified release.
- Share of refusals that name a valid next action and are successfully
  remediated.

### Versionability economics

- Share of requested authored surfaces by technical readiness, including Ready,
  Ready with conditions, Experimental, Not qualified, Requalification required,
  and Unsupported, reported alongside state class and handling.
- Agent time and compute required to qualify a new site-specific surface.
- Requalification work caused by a dependency upgrade.
- Share of fleet sites able to reuse reviewed declarations without reusing
  invalid site evidence.
- Semantic defects detected despite clean byte-level convergence.

### Safety invariants

- Zero silent authored-state loss.
- Zero unsupported Ready claims.
- Zero production-visible mutations before required checks and authority pass.
- Protected runtime preserved under every certified Release and Recover
  profile.
- External effects are prevented or sandboxed, or are live with explicit
  provider-state-restorable, compensatable, or irreversible semantics before
  authorization; unknown containment or recovery semantics never reach live
  systems.
- Every reported failure returns a documented public next action, including
  escalation when no automated action is safe.

The safety outcomes are release invariants, not growth metrics. Before
site-certified Ready becomes public, the roadmap must record the frozen cohort
rules and numeric launch thresholds. At minimum they cover median and p95
assessment time, preview time, qualification time and compute, human decisions
per release, requalification effort, initial-outcome coverage, and zero
false-Ready or protected-runtime-loss events in the qualification cohorts.

## Thesis tests

The following tests attack the assumptions most likely to invalidate the
product direction:

1. **Coverage economics:** cold-qualify a representative cohort of real
   WordPress and WooCommerce sites; measure bespoke effort per stack and per
   upgrade.
2. **False-Ready semantics:** prove rendered, API, and business oracles catch
   hidden-reference and ownership defects despite clean round trips.
3. **Live-writer recovery:** introduce runtime transactions after a checkpoint,
   force release failure, and prove the profile preserved or prevented them.
4. **Irreversible effects:** call a remote sink before failure and verify Duo
   prevented, sandboxed, or truthfully excluded it from recovery.
5. **External authority:** exercise concurrent Git and ERP/PIM ownership of the
   same fields and require an explicit, non-destructive resolution.
6. **One-way migration:** qualify or refuse a destructive asynchronous plugin
   migration with no valid down path.
7. **First-time usability:** external WordPress operators complete assess,
   preview, change, review, release, and recover through public surfaces without
   learning Duo internals.
8. **Upgrade maintenance:** change dependency versions and demonstrate that
   only dependent evidence is invalidated and regenerated.
9. **Fleet reuse:** repeat qualification across similar sites and measure safe
   declaration reuse, site-specific evidence cost, and human attention.

Coverage economics, false-Ready semantics, and first-time usability must be
validated before site-certified Ready becomes a public production claim.

## Ratification and change control

Ratification of this document makes the following product decisions:

- Branchable WordPress remains the product wedge; versionability is the
  enabling platform.
- Any reachable site receives either a bounded assessment of authorized,
  observable facts or a structured refusal; no site is universally declared
  branchable.
- Agencies and professional developer-operators are the initial commercial
  customer.
- Duo remains host-agnostic and bring-your-own-agent.
- The registry is the platform-certified baseline catalog; site-certified
  capability has distinct certification provenance and a named approving
  principal.
- The application contract consists of repository declarations, generated
  evidence, a certification attestation, environment-local bindings, and a
  generated projection.
- Qualification is cross-cutting; verified recovery is an operation profile,
  not an adoption rung.
- Agent autonomy stops at new business intent and consequential risk unless
  explicit organizational policy already supplies authority.
- Duo uploads no customer evidence by default, and ecosystem aggregation is
  deferred.
- Field adoption and user-surface closure precede broad new abstraction work.

### Ratification record

- Ratified by founder decision on 2026-08-14.
- Supersedes the two 2026-08-13 agent-era drafts and the RC1 working draft,
  whether retained locally or later archived as design history.
- The roadmap was aligned to the strategic sequence in the ratifying commit.
- Public positioning changes only when corresponding product surfaces ship;
  current platform-certified capability claims continue to come solely from
  the generated registry, and site-certified claims only from the generated
  per-site projection.
- Application-contract grammar enters the normative contract only through a
  versioned repository-format change.

### Amendment record

- **2026-08-20 — the claim model is exercised, not sealed.** Owner-delegated
  ruling, following the teardown train (#477–#480) that removed the
  certification-evidence apparatus. Immutable, content-addressed evidence
  bundles and the registered certification gate that verified them leave the
  target product; the target claim model is now the one that shipped. A
  capability is manifest-declared, disposition-reviewed by a named human who
  wrote down the reason, and conformance-tested by named suites against a live
  pair, with the generated capability document as the single projection of that
  model and the release gate byte-comparing it against its sources. Ed25519
  certification over exact adapter bytes, under a platform or
  customer-organization trust root, remains the certification path and is what
  Site-certified names. This amends the application contract's parts 2 and 3,
  qualification step 12, and Invalidation; the roadmap's site-certification rung
  and its matching standing decision were aligned in the same commit. The
  five-part contract, the provenance and readiness vocabularies, and every other
  decision in this document stand as ratified. One naming note, since the
  ratification record above is frozen: where this document says current
  platform-certified claims come solely from *the generated registry*, the
  artifact meant is *the generated capability document*
  ([docs/capabilities.md](capabilities.md)) — the prose above now uses that
  name, and the record keeps its original words.
- **2026-08-20 — the two registry phrases the census left ambiguous.** Naming
  resolution only; no decision in this document changes. The 2026-08-20 entry
  above renamed *the generated registry* to *the generated capability document*
  where the phrase meant the platform-certified claim catalog, and deliberately
  left two occurrences alone because they meant something else. Both are now
  named exactly:
  - **The versionability contract** — "the generated registry may decompose one
    customer operation into engine operations" meant neither catalog nor
    document but the **per-operation capability projection**: the
    `capability(stack, surface, operation, …)` evaluation sketched directly
    above it, which is `AdapterRegistry::report()`
    (`agent/src/Adapter/AdapterRegistry.php:319`, `duo-capability-report/v1`)
    run for one exact operation × surface × target probe, projected into this
    document's vocabulary by `ProjectionVocabulary` and surfaced by
    `duo capabilities` and `duo assess`. A decomposition into compile/plan/
    deploy/apply/promote/recapture/render-api is a per-operation answer about
    one target, not a row in a catalog.
  - **Explicit non-goals** — "no prose claim exists until their exact
    capabilities enter the generated registry" meant the **claim admission
    path**, which is now three named steps and not an artifact anything writes
    to: declared in an adapter manifest, reviewed into
    `manifests/dispositions.json` by a human who wrote down the reason, and
    projected into the generated capability document. The sentence's force is
    unchanged — a future scope earns prose by passing those gates, never by
    being written about first.

  The distinction both resolutions preserve is the one the teardown made
  load-bearing: a *claim* is authored and reviewed once and lives in the
  library; a *verdict* is computed per target, per operation, and lives only in
  a report. The retired registry blurred them by generating both, which is why a
  single word could stand for either.

[DESIGN.md](../DESIGN.md) remains untouched as the founding record.
