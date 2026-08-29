# Duo WP agency-adoption audit: 100 current blockers

Date: 2026-08-29  
Current revision analyzed: `2efd539df892558444152e1008216f30f66b722f` (`origin/main`)  
Original audit revision: `d37ae78be5a1beab408baf31d20082e346db64f7`

## Decision

**No-go for broad, portfolio-wide agency adoption.** A deliberately narrow pilot
can proceed only when its written acceptance boundary excludes every applicable
finding below and the agency supplies the external preview, recovery, secret,
inventory, and operational controls the selected scenario requires.

This is not a claim that Duo's correctness core is generally broken. Many
entries are explicit, fail-closed product boundaries. They are still adoption
blockers for an agency whose client portfolio crosses those boundaries.

## Counting and traceability

This is the single authoritative consolidation of the prospective-agency audit.
It contains exactly **100 unresolved findings**: 95 findings retained from the
original 100 plus five newly identified findings. Current IDs are a compact
1–100 sequence; each retained entry carries its original ID.

PR #573 closed five entries from the original audit, so they are not recounted:

| Original ID | Resolved finding |
| --- | --- |
| 1 | Fresh-checkout demo failed before it could start |
| 2 | The advertised demo image could not run the release verb |
| 3 | The shipped WooCommerce demo was not release-qualified |
| 4 | `assess` directed operators to a `classify` dead end |
| 62 | The normative term-meta specification contradicted the engine |

The delta from the original audit revision to current `main` is PR #573. Its
18-file change surface closes the five entries above; the other 95 findings
remain applicable. Source anchors in retained entries record the revision where
the evidence was originally measured. The five new entries cite current-main
paths and lines.

Terminology:

- **Defect**: current behavior contradicts the advertised path or remediation.
- **Boundary**: the product deliberately refuses the scenario.
- **Operational gap**: the agency must build or operate an external system or
  manual procedure.
- **Maturity gap**: the repository explicitly lacks the field or trust evidence
  needed for the claim.

## Verification baseline

At the original audit revision, `composer check`, all 331 then-registered
offline suites, and `make release-gate` were green. The advertised WooCommerce
journey and targeted regressions supplied the composed-path evidence recorded in
the retained findings. PR #573 was then reviewed, locally gated, merged, and its
five closures removed from this count.

This consolidation is documentation-only. Its current-main verification is
recorded in the pull request that introduces this file.

## Current findings 1–100

### 1. Multisite is categorically unsupported

**Traceability:** original finding #5

**Severity:** Blocker for affected clients  
**Type:** Boundary  
**Scenario:** A client runs WordPress Multisite or network-scoped plugin state.

The platform claim is `site_mode: single-site`
(`platform/adapter-library/capabilities/platform.json:76`), and connect refuses
anything that does not report `single-site`
(`cli/src/Command/ConnectCommand.php:158-165`). Adapter dispositions repeat the
same exclusion.

**Agency impact:** an agency cannot adopt Duo for any multisite client or
network-level authored state.

### 2. The platform version matrix excludes any site outside three narrow lines

**Traceability:** original finding #6

**Severity:** Blocker for affected clients  
**Type:** Boundary  
**Scenario:** A client cannot immediately change its WordPress, PHP, or database
version.

The pre-policy gate accepts only (`docs/compatibility-baseline.json`):

- WordPress `>=6.9.0 <7.2.0`, with only 6.9, 7.0, and 7.1 series claimed
  (`:59-68`).
- PHP `>=8.3.0 <8.5.0`, with only 8.3 and 8.4 series claimed (`:3-10`).
- MariaDB `>=11 <12` or MySQL `>=8.4 <8.5`; every other engine or line is
  refused (`:12-23`).

The local audit host's PHP 8.5.6 already triggered a doctor warning.

**Agency impact:** portfolio adoption begins with mandatory platform migrations,
not with Duo onboarding, for every client outside any one axis.

### 3. The runtime profile excludes several managed-host storage/process models

**Traceability:** original finding #7

**Severity:** Blocker for affected hosts  
**Type:** Boundary  
**Scenario:** A host uses Windows/BSD, shared/network storage, offloaded uploads,
object storage, or disables process-control functions.

The filesystem contract requires Darwin/Linux, local POSIX atomic rename,
`flock`, `fsync`, `lstat`, and `chmod`; network/offload/object-storage semantics
remain outside the profile (`docs/compatibility-baseline.json:25-39`). The
process contract additionally requires `/bin/sh`, `passthru`, `proc_*`,
`posix_kill`, and `posix_setsid` (`:41-57`). Missing any required primitive is a
pre-policy refusal.

**Agency impact:** hosts that restrict PHP process functions or abstract the
filesystem cannot be adopted without a new exact provider boundary.

### 4. Docker has no adoption/delivery path

**Traceability:** original finding #8

**Severity:** High  
**Type:** Boundary / onboarding gap  
**Scenario:** A client site is containerized and does not already carry Duo.

The guide says Docker must already have the agent installed or mounted; init
never delivers it (`docs/guides/quickstart.md:101-116`). The adoption contract is
explicit: “Docker has no adoption capability at this version”
(`docs/adoption.md:61-65`). The operator must also hand-create the exact seed
repository and mount/control it (`docs/guides/quickstart.md:423-505`).

**Agency impact:** the product cannot bootstrap a new Docker client; every
container platform needs a bespoke control-plane installation first.

### 5. Symlink-free repository topology excludes atomic-release layouts

**Traceability:** original finding #9

**Severity:** High  
**Type:** Boundary  
**Scenario:** A site reaches WordPress or its managed repository through a
release/current symlink or another symlink ancestor.

`repo_path` must be an ordinary directory reached without symbolic-link
ancestors (`docs/guides/quickstart.md:131-139`, `:498-501`). Code staging also
refuses stable symlinks (`docs/guides/code-updates.md:69-79`). Adoption checks
and the transfer transaction explicitly reject symlink destinations.

**Agency impact:** those clients must redesign their deployment topology before
adoption; configuring the resolved path does not solve every stable-symlink
restriction in the code materializer.

### 6. Branch previews require an agency-built environment provider

**Traceability:** original finding #10

**Severity:** Blocker for the advertised branch/rehearse workflow  
**Type:** Operational gap  
**Scenario:** An agency wants per-branch disposable previews.

Duo states that it orchestrates providers but supplies no hosting, and
`env materialize`, `env reap`, and `rehearse` refuse without one
(`docs/branch-environment-provider.md:8-13`). On the canned demo:

```text
duo env provider-check demo-target => BLOCKED
env 'demo-target': branch materialization requires machine-local environment_provider configuration
```

The provider must implement the closed canonical-JSON protocol and its resource,
snapshot, repository, URL, lease, TTL, and receipt capabilities. The shipped
reference provider is explicitly development-only.

**Agency impact:** “branch WordPress like code” requires a separate engineering
project against each hosting/control-plane estate.

### 7. Rehearsal is neither isolation nor qualification

**Traceability:** original finding #11

**Severity:** High  
**Type:** Boundary  
**Scenario:** A preview boots a store, membership site, form system, or CRM
integration with production-derived data.

The product does not strip/rebind credentials, deny outbound HTTP/mail/payment/
webhook/queue traffic, or verify containment
(`docs/guides/release.md:123-141`). It warns that plugins can send real mail or
call real payment APIs. Because containment is unknown, the rehearsal cannot
qualify Experimental or Uncertified capability.

**Agency impact:** the agency must build credential sanitization and egress
controls outside Duo, and the resulting preview still does not elevate an
unsupported adapter claim.

### 8. Code releases require exclusion of writers Duo cannot lock

**Traceability:** original finding #12

**Severity:** High  
**Type:** Operational gap  
**Scenario:** WordPress auto-updates, a package manager, a host control plane, or
shell automation can write `WP_CONTENT_DIR` during a release.

The database lease serializes Duo writers only. Operators must exclude package
managers, self-updaters, and every other filesystem writer during stage/finalize
(`docs/guides/code-updates.md:65-73`). The materializer explicitly is not an
adversarial filesystem-race sandbox.

**Agency impact:** hosts without a reliable external maintenance/exclusion
mechanism cannot safely use managed code deployment.

### 9. The production adapter library covers only 15 plugins

**Traceability:** original finding #13

**Severity:** High  
**Type:** Ecosystem gap  
**Scenario:** A client uses a plugin outside the shipped set or has custom
tables/entities.

`duo adapter list --format=json` reports 17 shipped rows. One is core and one is
the `duo-agency-cpt` excluded regression fixture, leaving 15 production plugin
adapters: ACF, Advanced Editor Tools, Classic Editor, Code Snippets, Contact
Form 7, Elementor, Ninja Forms, Paid Memberships Pro, Polylang, Redirection,
The Events Calendar, WooCommerce, WPS Hide Login, Yoast, and Yoast Duplicate
Post.

Anything else requires the agency to discover, model, test, certify, pin, and
maintain its own adapter before release can be authorized.

**Agency impact:** adapter engineering becomes prerequisite work for the long
tail of each client stack, not an edge case after rollout.

### 10. Ordinary plugin updates frequently exit the certified window

**Traceability:** original finding #14

**Severity:** High  
**Type:** Maintenance boundary  
**Scenario:** A client or host applies a normal plugin auto-update.

Several shipped ranges admit only one or two patch releases, for example:

- Advanced Editor Tools `>=5.9.2 <5.9.3`
- Classic Editor `>=1.7.0 <1.7.1`
- PMPro `>=3.8.2 <3.8.4`
- Redirection `>=5.9.0 <5.9.1`
- The Events Calendar `>=6.17.2 <6.17.4`
- WooCommerce `>=11.0.0 <11.0.2`
- WPS Hide Login `>=1.9.19 <1.9.20`

The runbook says `outside_version_range` is exactly what an ordinary WordPress
auto-update produces; the exits are widening/reviewing/re-pinning the adapter,
an evidence-bound boundary exercise, or forcing past with no evidence
(`docs/guides/code-updates.md:110-130`).

**Agency impact:** routine patching can immediately turn a releasable client
into a blocked requalification project.

### 11. Common ACF Pro/custom-schema workflows are unsupported

**Traceability:** original finding #15

**Severity:** High for affected sites  
**Type:** Adapter boundary  
**Scenario:** A client uses ACF Pro or creates CPT/taxonomy definitions in ACF UI.

The ACF disposition refuses Pro-only gallery, repeater, flexible-content, clone,
and custom/unknown field storage; it also refuses UI-created post-type/taxonomy
entities, several location owners, local PHP/JSON collisions, password fields,
and every ACF entity delete
(`adapter-packages/acf/package/disposition.json:49-89`).

**Agency impact:** the presence of the “certified ACF” adapter does not make
many ACF-heavy agency builds adoptable.

### 12. Key plugin entity deletions are intentionally unsupported

**Traceability:** original finding #16

**Severity:** High  
**Type:** Adapter boundary  
**Scenario:** A branch removes a product, form, template, schema, redirect, event,
or other plugin-owned entity.

Examples in reviewed dispositions include WooCommerce products/variations,
attributes, shipping zones and tax rates; ACF fields/groups; Contact Form 7
forms; Elementor library entities; Ninja Forms forms; PMPro levels/codes/groups;
Polylang entities; Redirection rows; and Events Calendar entities. These are
refused because complete cascade/reverse-reference semantics are not proven.

**Agency impact:** agencies cannot treat the repository as the full lifecycle
authority for important client content. Removal work needs plugin-specific
manual operations or new adapter evidence.

### 13. Production/trust evidence is explicitly pre-adoption

**Traceability:** original finding #17

**Severity:** Blocker for risk-sensitive agencies  
**Type:** Maturity gap  
**Scenario:** An agency needs third-party field evidence, bound audit evidence,
or signed application contracts before accepting production risk.

The roadmap says the evidence seal was removed and not replaced, no third-party
production site has adopted, no real target has field-qualified the automatic
verified profile, the contract trust root is empty so contracts remain unsigned,
and there is no public registry (`docs/roadmap.md:30-52`). The README likewise
says a first third-party production site is still next (`README.md:18-20`), and
capability claims are not digest-bound to a run (`README.md:84-88`).

**Agency impact:** internal suites are strong, but the assurance package an
agency would show clients, insurers, or auditors does not exist yet.

### 14. Verified rollback is a bring-your-own provider stack

**Traceability:** original finding #18

**Severity:** Blocker for production release safety  
**Type:** Operational and maturity gap  
**Scenario:** An agency requires automatic rollback of database, code, uploads,
and declared effects.

A production host must supply maintenance exclusion, checkpoint, code-release,
upload, and effect providers plus four recovery adapters
(`docs/recovery-runtime.md:1-17`, `:79-124`). Duo selects verified automatic
rollback only if that full provider set and controller policy pass. Docker does
not implement the recovery transport (`:31-36`); local is explicitly not
certified (`:38-61`). The roadmap says no field target has yet qualified for the
automatic profile.

The demo's `duo recover --list` confirmed the fallback: it listed two plain
database dumps and disclosed that the transport carried no rollback-authority
runtime.

**Agency impact:** safe production recovery is another host-integration product
the agency must build, certify, and operate.

### 15. Even verified rollback loses live writes and cannot reverse external effects

**Traceability:** original finding #19

**Severity:** Blocker for zero-loss transactional requirements  
**Type:** Recovery boundary  
**Scenario:** A store receives orders/sessions or emits mail, payments, refunds,
or webhooks during a failed release.

Every recovery profile, including `verified-automatic`, does not restore emails,
payment captures/refunds, delivered webhooks, third-party observations, or
orders/sessions written after the checkpoint
(`docs/guides/recovery.md:182-240`). A whole-database restore loses every order,
session, comment, and other write committed after the checkpoint (`:253-264`).

**Agency impact:** transactional sites need a real maintenance window and an
external reconciliation runbook; Duo cannot meet a zero-data-loss rollback SLO.

### 16. Every deploy/promote accumulates an unexpired whole-database dump

**Traceability:** original finding #20

**Severity:** High at fleet scale  
**Type:** Operational gap  
**Scenario:** An agency runs frequent releases across large client databases.

Each promote and standalone deploy retains another `.sql` checkpoint, and
nothing removes one automatically. Files have no expiry; the only deletion path
is explicit `duo recover --prune-retained=<keep-n> --confirm-prune`
(`docs/guides/recovery.md:74-128`). Even pruning keeps at least one dump per
verb, and only removes the SQL, not sibling artifacts/plans (`:146-167`).

**Agency impact:** disk consumption and sensitive database-dump retention become
manual per-site fleet operations. A missed prune can exhaust storage; an
overlong retention period enlarges the data-protection footprint.

### 17. Red readiness is not encoded consistently in command exit status

**Traceability:** original finding #21

**Severity:** High  
**Type:** Operational/API gap  
**Scenario:** An agency puts assessment and capability checks into unattended
portfolio onboarding.

On the live disposable target, `assess` exited 0 with 396 of 486 operation
projections either `Not qualified` or `Unsupported`; `capabilities` exited 0
with `ready:false`; and `status` exited 0 while reporting three provider
problems and saying the plan was safe. The CLI explicitly defines a blocked
assessment as success (`cli/duo:974-1008`), and provider problems are counted
but do not flip status (`docs/guides/capabilities-and-limits.md:657-670`).

This is not a recount of original finding 3's WooCommerce provider defect. The
additional issue is the generic automation contract: exit 0 can mean command
execution succeeded, a capability verdict is red, or promotion is declared
safe, depending on the verb. An agency must parse several unrelated JSON
schemas and reconstruct readiness rather than use a stable process-level gate.

### 18. The first supposedly non-mutating connection probe can run site effects

**Traceability:** original finding #22

**Severity:** High for effectful sites  
**Type:** Safety boundary  
**Scenario:** An agency probes an unfamiliar production site before installing
anything.

`duo connect` issues no explicit mutation, but its topology check uses `wp
eval`, which boots WordPress; the guide warns that startup code may have its own
effects (`docs/guides/quickstart.md:56-62`). Plugins and MU plugins can send
traffic, enqueue work, alter caches, or perform migrations during bootstrap.

**Agency impact:** the advertised first-contact probe is not a side-effect-free
inspection boundary. The agency must understand and contain existing startup
code before it can safely use the command intended to discover the site.

### 19. Guided onboarding requires a new remote reachable from two trust domains

**Traceability:** original finding #23

**Severity:** High  
**Type:** Onboarding / infrastructure gap  
**Scenario:** The controller and managed WordPress host do not share Git
credentials or outbound network policy.

`duo onboard --git-url` accepts only an empty repository reachable by both the
controller and WordPress target, each with its own credentials and SSH
known-host configuration (`docs/guides/quickstart.md:71-81`). Without it, the
workflow stops and leaves a handoff command to resume later (`:82-90`).

**Agency impact:** adoption requires provisioning an extra shared Git trust
path on the production host. Deploy-key policy, host egress restrictions, or a
non-empty existing site remote blocks the composed onboarding flow.

### 20. A local checkout does not select where `capture` writes

**Traceability:** original finding #24

**Severity:** High  
**Type:** Collaboration hazard  
**Scenario:** A developer checks out a feature branch locally and captures
editor changes expecting them to land there.

The connected checkout is review-only. `duo capture` always writes to the
environment's configured target `repo_path`, not the branch in the local
checkout (`docs/guides/quickstart.md:92-99`). The target must separately be
pointed at or materialized onto the feature branch first.

**Agency impact:** familiar Git branch context is not the write destination.
Without a separately managed target-branch handoff, captures can land in the
wrong repository branch and create cross-client or cross-feature confusion.

### 21. Machine-local adoption has no repeatable Duo update transaction

**Traceability:** original finding #25

**Severity:** High  
**Type:** Maintenance gap  
**Scenario:** An agency initially bootstraps a local target and later needs to
upgrade its agent and embedded adapter library.

SSH re-running `duo adopt` is the update mechanism, but machine-local adoption
is initial-only and refuses once a control plane exists
(`docs/guides/quickstart.md:232-244`; `docs/adoption.md:176-181`). The refusal
points to the environment's existing update path; Duo supplies no repeat local
adoption transaction.

**Agency impact:** after using Duo's own local bootstrap, ongoing control-plane
delivery moves back outside Duo and must be designed, secured, and made atomic
by the agency or host.

### 22. Standalone doctor proves presence, not intended release freshness

**Traceability:** original finding #26

**Severity:** High  
**Type:** Supply-chain / maintenance gap  
**Scenario:** A fleet operator checks whether installed agents match an approved
Duo release.

`duo doctor` confirms that an agent is present but does not know which checkout
or release was intended, so it cannot compare against an expected version. The
signed release descriptor needed to supply that expectation remains a future
distribution concern (`docs/adoption.md:214-223`).

**Agency impact:** a green standalone health check cannot prove that a client
has the approved agent/library build. Agencies need an external inventory and
distribution attestation to detect stale or wrong installations.

### 23. Custom WordPress content/control-plane layouts are refused

**Traceability:** original finding #27

**Severity:** Blocker for affected sites  
**Type:** Structural boundary  
**Scenario:** A client uses Bedrock, a custom content root, an explicit
`WPMU_PLUGIN_DIR`, or `SUNRISE`.

The control plane accepts only the standard `wp-content/mu-plugins` layout with
no explicit `WPMU_PLUGIN_DIR` and no `SUNRISE`; other layouts fail during
compile before a checkpoint or target write
(`docs/guides/capabilities-and-limits.md:672-680`).

This is distinct from original finding 9's symlink restriction: an ordinary,
non-symlink custom directory is still outside the supported layout.

### 24. SSH adoption needs production filesystem privileges many hosts withhold

**Traceability:** original finding #28

**Severity:** Blocker for restricted managed hosts  
**Type:** Hosting boundary  
**Scenario:** The agency receives WP-CLI access but cannot modify MU plugins or
create a persistent repository directory.

The SSH account must write WordPress's actual `WPMU_PLUGIN_DIR` and the
configured `repo_path` (`docs/guides/quickstart.md:131-143`; `docs/adoption.md:19-27`).
Read-only deploy users, immutable images, or hosts reserving MU plugins for the
platform cannot satisfy adoption.

**Agency impact:** WP-CLI and SSH reachability are insufficient; adoption needs
control-plane-level write authority in two protected production locations.

### 25. A contract with zero business journeys can verify as `pass`

**Traceability:** original finding #29

**Severity:** Blocker for release assurance  
**Type:** Verification / governance gap  
**Scenario:** A reviewer accepts the generated contract after resolving the
only schema-enforced placeholder.

The generated proposal listed “declare at least one journey” as review work,
but acceptance enforced only the unresolved external effect. The live contract
was accepted with `journeys: []`; `duo verify` then exited 0 with
`verdict:"pass"`, 81 uncovered surfaces, and 83 disclosures. The implementation
explicitly permits this (`cli/src/Release/JourneyOracle.php:28-38`, `:201-213`).

**Agency impact:** the typed verdict most likely to become an agency release
gate can be green while no customer-visible behavior was checked. Disclosures
do not prevent automation or dashboards from treating the release as verified.

### 26. Contract acceptance does not enforce the requested stack ceilings

**Traceability:** original finding #30

**Severity:** High  
**Type:** Governance gap  
**Scenario:** An agency uses generated proposals as the review checklist for a
new client.

The live proposal required decisions for WordPress and PHP ceilings, yet it was
accepted with `max:null` for WordPress, PHP, MariaDB, and WooCommerce. Proposal
generation deliberately produces null ceilings and review reminders
(`cli/src/Contract/ContractProposal.php:335-365`, `:501-506`), while validation
explicitly accepts null maxima (`cli/src/Contract/ApplicationContract.php:349-390`).

**Agency impact:** the review artifact says a compatibility boundary still
needs a human decision, but acceptance does not prove that decision happened.
The separate platform/adapter gates may still refuse an unsafe runtime, but the
application contract cannot serve as the agency's approved support envelope.

### 27. Business verification is limited to unauthenticated GET + substring

**Traceability:** original finding #31

**Severity:** High for transactional sites  
**Type:** Verification boundary  
**Scenario:** A client needs checkout, login, form submission, account, admin,
or payment workflow assurance.

Each journey is only a host-side HTTP GET with an expected status and expected
substring (`docs/guides/release.md:375-398`;
`cli/src/Release/JourneyOracle.php:115-190`). The grammar exposes no method,
headers, cookies/session, request body, multi-step state, or semantic response
oracle.

**Agency impact:** even a non-empty journey list cannot verify the business
flows that make many agency sites valuable. A separate end-to-end test system
must be integrated and its verdict governed outside Duo.

### 28. `release --from` is not repository delivery

**Traceability:** original finding #32

**Severity:** High  
**Type:** Deployment gap  
**Scenario:** A developer expects releasing `main` to put that revision on the
target.

Release resolves the ref locally, reads target `HEAD`, and refuses unless they
already match. It fetches, pushes, and checks out nothing
(`docs/guides/release.md:274-285`). Code materialization handles the code
descriptor, not the Git handoff of the site repository revision.

**Agency impact:** a separate secure Git/CD path must update every target to the
exact revision before Duo can authorize it. Duo cannot be the agency's sole
deployment orchestrator.

### 29. WordPress users are deliberately outside repository state

**Traceability:** original finding #33

**Severity:** High for memberships and multi-author sites  
**Type:** State-model boundary  
**Scenario:** A branch or new environment needs the same authors, staff, or
customer identities as the source.

Users are environment-local, never captured, and never auto-created. Post
authors may fall back with a warning, while an authored user-meta sidecar
requires an exact case-sensitive login and otherwise refuses before mutation.
No tracked work plans user synchronization
(`docs/guides/capabilities-and-limits.md:474-482`).

**Agency impact:** the agency must provision and reconcile accounts separately,
and identity mismatch can either silently change authorship or stop an apply.

### 30. Most common WordPress conflicts remain whole-record decisions

**Traceability:** original finding #34

**Severity:** High for parallel editorial work  
**Type:** Collaboration boundary  
**Scenario:** Production editors and a branch both modify menus, options,
metadata, tables, or the same complex block.

Field composition covers only post scalar groups, term fields, and whole
top-level post blocks. Attachments, menus, sidebars, options, user metadata,
typed tables, tombstones, and opaque containers remain atomic; blocks cannot be
merged internally or moved (`docs/guides/daily-workflow.md:208-224`).

**Agency impact:** common plugin/page-builder changes collapse to choose-all-
production or choose-all-branch decisions, losing the fine-grained semantic
merge agencies need for long-running client work.

### 31. Conflict evidence intentionally omits the values reviewers must compare

**Traceability:** original finding #35

**Severity:** High  
**Type:** Review-UX / privacy boundary  
**Scenario:** A reviewer must decide which side of a production/branch conflict
is correct.

The field diff is not a before/after view. Values, paths, titles, bodies,
metadata, options, and per-value hashes are omitted; automation receives only
presence/equality relations (`docs/guides/daily-workflow.md:181-206`). The
interactive exception shows only bounded sanitized labels and never writes
them to the decision artifact (`cli/duo:1391-1404`).

**Agency impact:** preserving privacy removes the evidence needed for informed
content review. Teams need a separate privileged comparison channel and then
must manually map its judgment back to Duo selectors.

### 32. Lower-level promotion can record success while deletions remain pending

**Traceability:** original finding #36

**Severity:** High  
**Type:** Deployment semantics hazard  
**Scenario:** Automation uses the documented `duo promote` primitive without
`--with-deletes`.

Ordinary apply/promote skips every unauthorized planned deletion, applies the
rest, and records the revision as applied; tombstones stay pending
(`docs/guides/capabilities-and-limits.md:493-500`). The composed `release` verb
refuses instead, but lower-level promotion intentionally does not
(`docs/guides/daily-workflow.md:420-437`).

**Agency impact:** a successful promotion does not mean the target matches the
repository's deletion intent. Pipelines must understand pending tombstones and
must not equate the recorded revision with full convergence.

### 33. Applying over ordinary drift mutates first and fails afterwards

**Traceability:** original finding #37

**Severity:** High  
**Type:** Failure-atomicity gap  
**Scenario:** Production changes after planning and an operator runs apply
without first recapturing.

Apply does not refuse on ordinary drift before mutation. It writes other plan
items, preserves drifted entities, then fails post-apply convergence and leaves
an `incomplete_apply` marker (`docs/guides/capabilities-and-limits.md:508-523`).

**Agency impact:** a predictable stale-plan condition creates a partially
changed target and a recovery/retry workflow rather than a no-op refusal. This
raises the operational cost of concurrent client editing.

### 34. Adapter distribution has no HTTPS install transport

**Traceability:** original finding #38

**Severity:** High at fleet scale  
**Type:** Distribution boundary  
**Scenario:** An agency publishes private adapters from an artifact registry or
authenticated web endpoint.

The adapter index itself is unsigned and exactly one install transport ships:
`file://`. An HTTPS entry may be discovered but install refuses and instructs
the operator to mirror the certificate and adapter files locally
(`docs/guides/adapter-authoring.md:2095-2124`).

**Agency impact:** every controller needs a separate trusted mirroring and
distribution mechanism before Duo's verified installer can run. Registry auth,
revocation, availability, and cache policy all stay outside the product.

### 35. Out-of-tree adapters cannot introduce executable provider code

**Traceability:** original finding #39

**Severity:** Blocker for affected custom plugins  
**Type:** Adapter-authoring boundary  
**Scenario:** A custom adapter needs plugin-specific repair semantics but the
plugin itself cannot be changed to bundle a provider.

Site discovery, signed installation, and manifests ship, but an out-of-tree
adapter cannot introduce executable code outside an installed plugin. The
guide explicitly forbids working around this with manifest fields or copied
PHP (`docs/guides/adapter-authoring.md:2079-2093`).

**Agency impact:** some custom/legacy plugins cannot be made releasable by an
agency-authored package alone. The agency must modify the plugin, upstream a
provider, or accept an unsupported boundary.

### 36. Full adapter validation executes the package being evaluated

**Traceability:** original finding #40

**Severity:** High  
**Type:** Security boundary  
**Scenario:** An agency evaluates an unfamiliar third-party adapter package.

Resolving an interpreter or regenerator requires its PHP file, runs its top
level, and instantiates its constructor. `--no-code` avoids execution but
deliberately leaves the code half unverified
(`docs/guides/adapter-authoring.md:646-670`; `cli/duo:452-460`).

**Agency impact:** there is no fully validating, non-executing quarantine step.
The agency must perform source review/sandboxing before obtaining the validation
result that would normally inform whether the package is trustworthy.

### 37. Live adapter observation executes third-party registration callbacks

**Traceability:** original finding #41

**Severity:** High for production discovery  
**Type:** Security / observation boundary  
**Scenario:** An agency gathers redacted evidence from an unfamiliar live site.

`adapter-observe` keeps normal plugin/provider registration and capability
negotiation enabled. Third-party callbacks may have side effects before or
during evidence collection, even though Duo invokes no provider action
(`docs/guides/capabilities-and-limits.md:586-603`).

**Agency impact:** the redacted “observation” verb is not an isolated evidence
collector. Running it safely requires the same trust in installed callbacks as
normal WordPress execution.

### 38. The adapter grammar still cannot model several measured plugin patterns

**Traceability:** original finding #42

**Severity:** High  
**Type:** Engine expressiveness boundary  
**Scenario:** The agency is willing to author an adapter, but the plugin uses a
shape the generic engine cannot faithfully apply.

The generated limitation ledger names open primitives for a bounded verified
postcondition, dynamic derived option-name references, post-apply type
registration, structured-leaf text, and table-scoped cache invalidation
(`docs/guides/adapter-authoring-limitations.md:1-20`). Its measured examples
include WPForms derived locator state and three unrepresentable Custom Post Type
UI behaviors (`:21-39`).

This is distinct from original finding 13's small shipped catalog. Even after
funding adapter authoring, an agency cannot close these sites without new engine
primitives and adversarial platform coverage.

### 39. Fleet census does not collect its own fleet inventory

**Traceability:** original finding #43

**Severity:** High at portfolio scale  
**Type:** Fleet-management gap  
**Scenario:** An agency wants to rank adapter demand or prove rollout coverage
across dozens of clients.

`duo census` has no environment, target, WordPress, or registry connection. It
only folds `duo-assess-inventory/v1` documents the operator already produced
and transported locally (`cli/duo:1408-1439`).

**Agency impact:** scheduling site probes, collecting results, handling stale or
missing submissions, client isolation, and fleet completeness are all external
control-plane responsibilities. A census can be internally correct while the
agency has no proof that every current site reported.

### 40. Preview TTL is metadata, not automatic cleanup

**Traceability:** original finding #44

**Severity:** High for preview fleets  
**Type:** Resource-lifecycle gap  
**Scenario:** Developers create disposable environments and assume expiry
limits cost or exposure.

`--ttl` publishes observable expiry metadata and authorizes no deletion
(`docs/guides/release.md:84-92`). TTL never triggers cleanup; `duo env reap` is
the sole deletion path (`docs/guides/capabilities-and-limits.md:726-735`).

**Agency impact:** abandoned previews persist until an external scheduler calls
reap successfully. Cost control, data-retention SLOs, and cleanup alerting must
be built around Duo.

### 41. Paired environments must be upgraded in one coordinated window

**Traceability:** original finding #45

**Severity:** High  
**Type:** Fleet-maintenance boundary  
**Scenario:** An agency rolls a new Duo agent/library gradually through
production, stage, and preview environments.

Refresh/rebase compare live and locally compiled adapter/platform bytes. A
mixed-generation pair refuses `production adapter contract does not match
--production-ref`; there is no override, and every environment in the pair must
be re-adopted from the same checkout in the same window
(`docs/adoption.md:267-290`).

**Agency impact:** canary and rolling control-plane upgrades are unavailable for
paired workflows. The larger the portfolio and environment graph, the larger
the coordinated maintenance window.

### 42. Normal adapter maintenance invalidates every affected site artifact

**Traceability:** original finding #46

**Severity:** High  
**Type:** Fleet blast radius  
**Scenario:** An agency fixes or improves a shared custom adapter provider,
interpreter, regenerator, manifest, or disposition.

Those bytes are adapter identity. An edit moves the adapter digest and manifest
hash; deployed compiled artifacts refuse with
`compiled_artifact_manifest_mismatch`, and repository pins stop matching. Each
site must be recompiled and its reviewed pin updated
(`docs/adoption.md:365-380`).

**Agency impact:** a one-byte adapter fix is not centrally consumable runtime
maintenance. It creates per-site review, pin, compile, and rollout work across
the whole client cohort that uses it.

### 43. Default retained recovery checkpoints are plaintext database dumps

**Traceability:** original finding #47

**Severity:** Blocker for strict data-handling clients  
**Type:** Security / data-at-rest gap  
**Scenario:** A site contains customer, health, membership, payment-adjacent, or
other regulated data.

Ordinary promote and deploy retain the pre-release database as a **plain**
`.sql` file under the target repository's `.duo/checkpoints/`
(`docs/guides/recovery.md:74-94`). The encrypted checkpoint is part of the
separately supplied verified rollback provider, not the default retained file.

This is not original finding 20's accumulation/retention problem. Even one
fresh checkpoint creates an additional plaintext production-data copy whose
disk encryption, backup propagation, access logging, and erasure policy Duo
does not manage.

### 44. Third-party rollback bytes live outside Git and can disappear

**Traceability:** original finding #48

**Severity:** High  
**Type:** Recovery dependency  
**Scenario:** A plugin rollback is needed after its old version disappears from
the registry, host cache, or premium-vendor portal.

Locked third-party component bytes are deliberately absent from Git. If no host
or registry still holds the version, rollback is impossible without an agency-
retained archive; the runbook requires the agency to operate that archive store
(`docs/guides/code-updates.md:332-341`).

**Agency impact:** Duo's content-addressed lock is not an artifact escrow.
Reliable rollback adds a durable private archive service and restore drills to
the agency platform.

### 45. Plugin migrations have no general reverse path

**Traceability:** original finding #49

**Severity:** Blocker for unsafe schema migrations  
**Type:** Recovery boundary  
**Scenario:** A plugin update migrates production data and the new code must be
rolled back.

The runbook requires either rolling code back before migration or restoring code
and the pre-migration database together. Duo cannot supply plugin down-
migrations, and most plugins do not; it specifically calls code-only rollback
against a migrated database an unsolved state
(`docs/guides/code-updates.md:319-330`).

**Agency impact:** a routine plugin release may require a full database rewind,
with all post-checkpoint writes lost, rather than a forward-compatible code
rollback. Maintenance-window and business-continuity requirements rise sharply.

### 46. A retained checkpoint becomes unrestorable after a later release begins

**Traceability:** original finding #50

**Severity:** High  
**Type:** Recovery-history boundary  
**Scenario:** An agency discovers a delayed regression and selects the older
release's still-present checkpoint.

If any newer promotion session began, recovery refuses the older checkpoint at
step 1 with `promotion_abort_session_superseded`, even when its SQL and artifact
files still exist. The refusal is non-forceable; the remedy is the backup
provider outside Duo (`docs/guides/recovery.md:296-330`).

This is distinct from original finding 19's unavoidable loss of live writes:
the additional issue is that the product cannot select the older restore point
at all. File presence and catalog visibility do not imply recoverability.

### 47. Large plans have no paginated complete view

**Traceability:** original finding #51

**Severity:** High for large sites  
**Type:** Operability boundary

Filtered plan views hard-cap ordinary rows at 200 and version 1 has no cursor.
The only complete escape is the full unfiltered JSON plan
(`docs/guides/capabilities-and-limits.md:132-168`).

**Agency impact:** a portfolio dashboard cannot page safely through a large
plan. It must ingest an unbounded full document or accept an incomplete,
non-authoritative display projection.

### 48. Environment configuration records presence, not the intended value

**Traceability:** original finding #52

**Severity:** High  
**Type:** Configuration-model boundary

`class: "env"` values are never captured, so Duo has no record of what a
production credential or endpoint should be. It checks only that the option is
non-empty (`docs/guides/capabilities-and-limits.md:170-181`).

**Agency impact:** a wrong, stale, or cross-client secret is green. Agencies
need a separate secret/configuration source of truth and drift checker.

### 49. The public `env-set --value` form exposes secret bytes

**Traceability:** original finding #53

**Severity:** High for shared hosts  
**Type:** Security footgun

The safe `--stdin` form exists, but the accepted `--value` form places the value
in shell history and process listings
(`docs/guides/capabilities-and-limits.md:183-211`).

**Agency impact:** one plausible operator invocation can expose client secrets
to shell-history collection, process monitors, support tooling, and other users
on a shared controller. Policy must ban an otherwise supported CLI form.

### 50. A remote `env-set` can wait forever after handoff

**Traceability:** original finding #54

**Severity:** High  
**Type:** Operational boundary

After the value is handed to the target, termination is deferred and the host
wait has no timeout because a timeout could not prove that the remote write
stopped (`docs/guides/capabilities-and-limits.md:193-204`).

**Agency impact:** an unattended credential rollout can hang a worker
indefinitely. Killing it loses the authoritative result while the remote write
may still complete.

### 51. Environment provisioning works only for whole options

**Traceability:** original finding #55

**Severity:** High for plugin-heavy sites  
**Type:** State-model boundary

`env_missing` and `env-set` operate on options only. They cannot provision post
meta, term meta, or one key inside an authored structured option
(`docs/guides/capabilities-and-limits.md:208-216`).

**Agency impact:** environment-specific plugin configuration in common meta or
structured settings requires a separate provisioning system and cannot share
Duo's readiness/remediation path.

### 52. A suspicious-secret match blocks nothing

**Traceability:** original finding #56

**Severity:** High  
**Type:** Security-assurance gap

Only `hard_match` aborts capture. The broader key-name-plus-shape `suspicious`
classification merely adds a visual flag and is explicitly non-blocking
(`docs/guides/capabilities-and-limits.md:441-464`).

**Agency impact:** secret shapes outside the closed high-confidence patterns can
enter Git after a missed human review. The repository cannot be treated as
having a comprehensive secret-prevention gate.

### 53. Secret scanning in post bodies is warning-only

**Traceability:** original finding #57

**Severity:** High for editorial sites  
**Type:** Security boundary

Authored options and metadata receive a blocking secret gate, but post bodies
are warn-only by design (`DESIGN.md:48-50`).

**Agency impact:** credentials pasted into a page, reusable block, code sample,
or private editorial note can be committed to the site repository. A separate
content DLP/secret scan is mandatory before push.

### 54. Personal-data scanning covers user meta only

**Traceability:** original finding #58

**Severity:** High for regulated clients  
**Type:** Privacy boundary

The PII gate is scoped exclusively to authored user-meta sidecars
(`docs/guides/capabilities-and-limits.md:466-472`). It does not make equivalent
claims for post bodies, options, post meta, or custom tables.

**Agency impact:** agencies cannot use a green capture as evidence that the Git
repository is free of personal data. A broader data-discovery/compliance gate
must run outside Duo.

### 55. Application-contract approval is unsigned by default

**Traceability:** original finding #59

**Severity:** High for controlled releases  
**Type:** Governance gap

`contract accept` writes `attestation.state: "unsigned"`; the trust-root file
ships absent, and signing is a separate optional verb
(`docs/modules/cli-Contract.md:15-17`,
`docs/guides/capabilities-and-limits.md:374-384`). The disposable run accepted
and consumed an unsigned contract.

**Agency impact:** release authorization can rely on a mutable repository file
without cryptographic proof of who approved it. An agency must provision keys,
expiry policy, and the separate attest step before treating the contract as an
approval record.

### 56. “Site-certified” can mean no site was exercised

**Traceability:** original finding #60

**Severity:** Blocker for assurance-sensitive clients  
**Type:** Certification semantics gap

The normal site-certification command signs grammar acceptance with
`exercised:false`, no tests, and no artifacts. Nevertheless the readiness word
is `Site-certified`, and that certification admits deploy/promote once pinned
(`docs/guides/adapter-authoring.md:1617-1627`).

**Agency impact:** the prominent certification label is not an executed
compatibility result. Agencies must build their own qualification evidence and
teach reviewers to inspect the subordinate `exercised` fact every time.

### 57. Spec-v2 adapter typos load successfully and do nothing

**Traceability:** original finding #61

**Severity:** High  
**Type:** Adapter-safety boundary

The loader closes unknown top-level keys only for spec version 3. A version-2
manifest with an invented or transposed section still loads `ok` and ignores it
(`docs/guides/adapter-authoring.md:813-825`). The behavior is pinned by the
spec-v3 regression.

**Agency impact:** a custom adapter can appear valid while silently failing to
model the state the author thought it declared. Every v2 adapter needs an
additional signer/schema check or migration to v3.

### 58. Ordinary permalink links are not identity-stable

**Traceability:** original finding #63

**Severity:** High for content branching  
**Type:** Portability boundary

Pretty permalinks tokenize as `{{home}}/<path>` and remain correct only while
slugs match. UUID-precise `{{link:<uuid>}}` is still reserved for a future
format; only query-string links receive UUID precision today
(`spec/repo-format.md:46-59`).

**Agency impact:** a slug/parent rename on one branch can leave internal links
pointing to an obsolete path after merge or promotion.

### 59. Revisions and auto-drafts never enter the repository

**Traceability:** original finding #64

**Severity:** High for editorial clients  
**Type:** State-model boundary

Post revisions and auto-drafts are explicitly excluded from capture
(`spec/repo-format.md:103-108`).

**Agency impact:** branch creation and disaster recovery do not preserve the
editorial history clients use for audit, restoration, or legal review. That
history needs a separate database backup policy.

### 60. Password-protected posts cannot be captured

**Traceability:** original finding #65

**Severity:** Blocker for affected sites  
**Type:** State-model boundary

A non-empty `post_password` is treated as authored secret material, but the
format has no portable representation, so capture refuses the post
(`spec/repo-format.md:103-108`).

**Agency impact:** one password-protected client page blocks the canonical
capture unless the feature is removed or the content is kept outside managed
scope.

### 61. Authored user meta cannot have repeated rows

**Traceability:** original finding #66

**Severity:** High for membership/profile plugins  
**Type:** Representation boundary

The user-meta sidecar rejects multi-row values instead of representing them
(`spec/repo-format.md:126-139`).

**Agency impact:** plugins that intentionally use repeated `usermeta` keys
cannot put that authored profile/configuration state under Duo management.

### 62. Deleting a user-meta sidecar does not delete its target data

**Traceability:** original finding #67

**Severity:** High  
**Type:** Deletion-semantics boundary

Only an empty retained sidecar can explicitly delete formerly authored keys.
Removing the sidecar file itself leaves target metadata untouched
(`spec/repo-format.md:137-139`).

**Agency impact:** the intuitive Git deletion gesture is non-authoritative and
can leave retired access/profile state on every environment.

### 63. Comments are unmanaged runtime data

**Traceability:** original finding #68

**Severity:** High for community/editorial sites  
**Type:** State-model boundary

Comments never receive repository identity and the shipped rebuild pass does
not manage comment recounts (`DESIGN.md:52-60`, `:119-122`).

**Agency impact:** comment content, moderation state, and comment-linked branch
behavior do not travel with a feature environment or site repository.

### 64. Orders, sessions, and submissions never enter branch state

**Traceability:** original finding #69

**Severity:** High for commerce/forms  
**Type:** State-partition boundary

Runtime data such as sessions, orders, caches, and submissions never enters the
repository (`docs/guides/daily-workflow.md:20-28`).

**Agency impact:** a Git branch is not a complete reproducible client site.
Testing scenarios that need realistic transactional state require provider
snapshots, sanitization, and a separate retention/compliance model.

### 65. General PHP-object serialization is outside certified scope

**Traceability:** original finding #70

**Severity:** Blocker for affected builders/plugins  
**Type:** Representation boundary

Only whole-body verbatim and opaque-byte sidecar forms ship. General in-place
reference substitution for PHP object serializations has not shipped, so those
values remain outside certified scope (`DESIGN.md:63-69`).

**Agency impact:** plugins/page builders that store authored state as serialized
objects cannot be made safely portable by ordinary classification.

### 66. The code release owns only extension roots, not a full site

**Traceability:** original finding #71

**Severity:** High  
**Type:** Deployment boundary

The code payload owns plugins, themes, and user MU plugins, but not WordPress
core, `wp-config.php`, uploads, caches, drop-ins, language packs, or other
content directories (`spec/repo-format.md:371-384`).

**Agency impact:** Duo cannot be the agency's one full-stack deployment source.
Core updates, config, translations, host drop-ins, and adjacent webroot files
need another coordinated release system.

### 67. Any in-scope non-InnoDB table blocks capture

**Traceability:** original finding #72

**Severity:** Blocker for affected legacy sites  
**Type:** Database boundary

Capture requires a consistent MVCC snapshot and refuses upfront when any
in-scope table is not InnoDB (`DESIGN.md:115-120`).

**Agency impact:** an otherwise supported MySQL/MariaDB site with one legacy
MyISAM plugin table must migrate storage engines or exclude that authored state
before adoption.

### 68. Large captures hold the database snapshot through filesystem work

**Traceability:** original finding #73

**Severity:** High for large sites  
**Type:** Scalability / availability gap

The consistent-snapshot transaction stays open while canonical files are
written, hashed, compiled, and published. The design warns about prolonged
MVCC history and row locks (`DESIGN.md:119`).

**Agency impact:** large catalogs/content estates can create undo-history
pressure and longer lock windows merely to capture a branch. Agencies need
maintenance scheduling and database monitoring rather than an online,
low-impact capture guarantee.

### 69. New repositories may not carry third-party component bytes in Git

**Traceability:** original finding #74

**Severity:** High for established agency workflows  
**Type:** Repository-model boundary

The current format-2 path allows exactly locked third-party components or
declared first-party components. The former vendor-everything mode and
vendored-archive origin are retired
(`docs/guides/code-updates.md:391-423`).

**Agency impact:** agencies that deliberately vendor premium/public plugin
bytes for reproducibility, audit, air-gapped delivery, or escrow must redesign
their repository and artifact-retention model.

### 70. Locally patched or repackaged plugins become unsourced

**Traceability:** original finding #75

**Severity:** High  
**Type:** Code-supply boundary

A component locks only when the fetched/imported archive unpacks to the exact
installed tree. One local patch or upstream repack makes it unsourced; the only
remedies are importing the exact archive or declaring genuinely owned code
first-party (`docs/guides/code-updates.md:436-451`).

**Agency impact:** common emergency vendor patches cannot proceed as an
ordinary locked dependency. They require a new artifact and governance
decision before the site can compile.

### 71. Docker code resolution rejects named or read-only repository mounts

**Traceability:** original finding #76

**Severity:** Blocker for affected container platforms  
**Type:** Transport boundary

The host resolver needs a writable bind-mount source. A named volume or
read-only mount has no writable host directory and refuses
`code_resolve_transport_unsupported`
(`docs/guides/code-updates.md:546-562`).

**Agency impact:** immutable or volume-backed Docker deployments cannot use the
locked-code path even after the separately required Docker control-plane setup.

### 72. Dependency resolution mutates the repository before release protection

**Traceability:** original finding #77

**Severity:** High  
**Type:** Transaction-boundary gap

Deploy/promote resolve locked code immediately before compile, outside the
promotion lease, with no checkpoint or compensation
(`docs/guides/code-updates.md:606-622`).

**Agency impact:** a failed release can still leave resolved/pushed component
bytes in the target repository even though the protected promotion never
started. Repository cleanup and concurrency control remain external.

### 73. Every deploy stages every descriptor file

**Traceability:** original finding #78

**Severity:** High for large codebases  
**Type:** Deployment scalability gap

The lock split reduces Git history, not deployment work: every deploy still
stages every descriptor file (`docs/guides/code-updates.md:629-633`).

**Agency impact:** large plugins/themes pay full file-walk/staging cost for
small releases. There is no incremental code-deploy path for fleets with tight
maintenance windows.

### 74. Release checkpoints are full dumps inside the write-exclusion window

**Traceability:** original finding #79

**Severity:** High for large databases  
**Type:** Availability / recovery tradeoff

Deploy/promote take a complete database export after the lease. The documented
alternative is `--no-checkpoint`, which avoids the cost by leaving nothing to
restore if a later phase fails (`docs/guides/code-updates.md:34-47`).

**Agency impact:** a large client must choose between a long, disk-heavy
protected window and no release checkpoint. No incremental/snapshot-native
default closes the tradeoff.

### 75. Plugin upgrades may still be migrating after deploy succeeds

**Traceability:** original finding #80

**Severity:** High for large commerce sites  
**Type:** Lifecycle completeness gap

Duo guarantees that plugin upgrade code starts in a fresh process, but
WooCommerce may continue migrations asynchronously through Action Scheduler;
instant completion is explicitly not guaranteed
(`docs/guides/code-updates.md:635-668`).

**Agency impact:** the following state apply or verification can run while a
large catalog's own schema/data jobs remain outstanding. Completion requires
plugin-specific external monitoring and gating.

### 76. The first version change after an unrecorded baseline is invisible

**Traceability:** original finding #81

**Severity:** High  
**Type:** Drift-detection gap

A plugin with no recorded version baseline is skipped rather than reported as
drift (`docs/guides/code-updates.md:251-281`).

**Agency impact:** a newly activated or previously inactive component can
change before its first baseline without producing the version-drift refusal
an agency expects from fleet policy.

### 77. Content-only promotions still execute extension lifecycle hooks

**Traceability:** original finding #82

**Severity:** High for effectful plugins  
**Type:** Side-effect boundary

`retire` and `activate` run on every promotion, code or not, and WordPress hooks
fire in that window (`docs/guides/capabilities-and-limits.md:424-435`).

**Agency impact:** publishing a page or option change can re-run plugin
deactivation/activation effects, requiring external-effect declarations and
recovery reasoning for a release with no code change.

### 78. Successful initial adoption creates no rollback checkpoint

**Traceability:** original finding #83

**Severity:** High  
**Type:** Onboarding recovery gap

The initial state capture performed by `duo init` is explicitly not a promotion
rollback checkpoint (`docs/guides/quickstart.md:313-323`).

**Agency impact:** the highest-risk transition—first capture and repository
creation—does not create the same recoverable before-image later promotions
do. Agencies must take and retain an independent pre-adoption backup.

### 79. Adoption integrity depends on agency-written plugin checksums

**Traceability:** original finding #84

**Severity:** High  
**Type:** Acceptance-test gap

The runbook requires operators to invent plugin-specific checksums for tokens,
caches, indexes, and tables. Capture may exit 0 even when those checksums move,
which the guide defines as a failed adoption
(`docs/guides/quickstart.md:325-331`, `:408-417`).

**Agency impact:** Duo's success result is not sufficient acceptance evidence.
Every client needs bespoke database knowledge and a manually maintained
non-perturbation oracle.

### 80. Portability qualification is a manual second-environment exercise

**Traceability:** original finding #85

**Severity:** High  
**Type:** Onboarding completeness gap

One captured site proves capture, not portability. The documented proof needs
an independent target, matching code, transfer, apply, recapture, two runtime
checksum sets, and byte comparison (`docs/guides/quickstart.md:607-624`).

**Agency impact:** adoption cannot be qualified from the production site alone,
and Duo supplies no composed command that produces this acceptance record.

### 81. Natural-key collision adoption is a manual authorization

**Traceability:** original finding #86

**Severity:** High  
**Type:** Identity-mapping risk

`--adopt-by-slug` can bind independently created posts, terms, or menus. The
guide requires every collision to be inspected and the entity-kind list to be
narrowed manually (`docs/guides/quickstart.md:626-629`).

**Agency impact:** large existing sites need human identity reconciliation;
one wrong authorization can bind unrelated content across environments.

### 82. Interrupted initialization has no resume or bounded cleanup path

**Traceability:** original finding #87

**Severity:** High  
**Type:** Recovery gap

When init lacks a completed deletion manifest, Duo deliberately retains the
sealed journal and partial payload. The prescribed retry is to archive the
entire repository, recreate an empty directory, and start again
(`docs/guides/quickstart.md:575-592`).

**Agency impact:** a controller crash during onboarding becomes a manual
forensic/archive operation rather than an idempotent resume.

### 83. Classification batches are all-or-nothing and stale on any new write

**Traceability:** original finding #88

**Severity:** High for active sites  
**Type:** Review-workflow boundary

A batch is bound to the exact pending evidence. Any intervening write makes it
stale, partial batches refuse, and one decision layer may reveal another full
loop (`docs/guides/quickstart.md:390-406`).

**Agency impact:** review of an aged, actively edited production site can be
starved by normal traffic/editor changes. The agency must freeze writes or
continually restart a full decision batch.

### 84. An existing non-seed policy cannot use the initializer

**Traceability:** original finding #89

**Severity:** High  
**Type:** Onboarding boundary

Once `site.duo.json` differs from the exact seed, `duo init` refuses
`existing_configuration` and the operator must continue with the lower-level
doctor/pending/classify/capture sequence
(`docs/guides/quickstart.md:528-548`).

**Agency impact:** sites that pre-author policy or inherit an earlier Duo
configuration lose the composed, confirmed initialization transaction.

### 85. Branch materialization requires the clean currently checked-out branch

**Traceability:** original finding #90

**Severity:** High for automation  
**Type:** Git-workflow boundary

The branch ref must resolve to the clean branch currently checked out
(`docs/guides/daily-workflow.md:57-84`).

**Agency impact:** detached revisions, remote-only refs, dirty review
worktrees, and many CI checkout patterns cannot be materialized directly. Jobs
must maintain a dedicated mutable checkout and serialize its branch state.

### 86. Cross-branch plugin-version skew does not fail merge-check

**Traceability:** original finding #91

**Severity:** High  
**Type:** Merge-safety gap

`duo merge-check` reports differing locked plugin versions only as a warning and
does not change the exit code (`docs/guides/daily-workflow.md:323-330`).

**Agency impact:** an automated merge gate can pass while state from one plugin
schema is being merged with code from another. The required code-first,
migrate, recapture ordering remains procedural.

### 87. Branch-provider actions are capped at 60 seconds

**Traceability:** original finding #92

**Severity:** Blocker for slow hosting operations  
**Type:** Provider-protocol boundary

The machine-local provider timeout is an integer from 1 through 60, applied per
action (`docs/branch-environment-provider.md:27-56`).

**Agency impact:** a hosting platform whose snapshot, restore, attach, or
repository materialization cannot reliably answer each synchronous action in
one minute cannot implement the protocol without a separate asynchronous
receipt layer hidden behind the provider.

### 88. Provider failures hide all provider diagnostics

**Traceability:** original finding #93

**Severity:** High  
**Type:** Operability / support gap

On every failure, provider stdout and stderr are redacted and reach neither the
terminal nor the journal (`docs/branch-environment-provider.md:58-66`).

**Agency impact:** the most deployment-specific failure details are unavailable
through Duo. Agencies must build a second, correlated provider logging system
to diagnose snapshot, provisioning, or cleanup failures.

### 89. Creating a branch environment freezes the production source

**Traceability:** original finding #94

**Severity:** High for busy sites  
**Type:** Availability boundary

Materialization runs `snapshot-prepare`, which freezes the named source before
the coherent database/media set is created
(`docs/branch-environment-provider.md:15-25`, `:246-311`; also
`docs/guides/daily-workflow.md:70-80`).

**Agency impact:** every preview refresh consumes a production freeze window
whose duration and traffic behavior are supplied by the agency's provider.

### 90. Scoped promotion is SSH-only

**Traceability:** original finding #95

**Severity:** Blocker for local/Docker scoped delivery  
**Type:** Transport boundary

The shipped mutation profile is explicitly a narrow SSH-only form
(`docs/guides/capabilities-and-limits.md:801-816`). The scoped wire regression
confirmed that even a fully configured local rollback authority receives
`scoped_promotion_unavailable`.

**Agency impact:** local and Docker environments may preview scopes but cannot
use the same bounded promotion transaction.

### 91. Scoped promotion omits core content entity families

**Traceability:** original finding #96

**Severity:** High  
**Type:** Scope-surface boundary

The SSH profile accepts only selected options, declared snapshot tables,
sidebars, user meta, and option/table tombstones
(`docs/guides/capabilities-and-limits.md:803-810`). Posts, terms, menus, and
attachments are not in that promotion list.

**Agency impact:** the feature named scoped promotion cannot deliver the most
common agency request—a bounded page/menu/taxonomy/media change.

### 92. Scoped promotion bypasses the ordinary code and action lifecycle

**Traceability:** original finding #97

**Severity:** High for plugin-backed state  
**Type:** Composition boundary

The scoped path invokes no code, uploads, lifecycle, native/provider actions,
or ordinary release path (`docs/guides/capabilities-and-limits.md:811-820`).

**Agency impact:** a selected database record that depends on plugin code,
media, a regenerator, or provider work cannot move atomically with those
dependencies. The operator must widen back to a full release.

### 93. A sealed scoped promotion has no later rollback

**Traceability:** original finding #98

**Severity:** High  
**Type:** Recovery boundary

Failures before fresh verification restore the database checkpoint; after the
durable seal, retries can only finish forward and no public scoped rollback
exists (`docs/guides/capabilities-and-limits.md:811-822`,
`docs/recovery-runtime.md:63-71`).

**Agency impact:** a problem discovered after the scoped commit cannot be
reversed with the same scoped authority. Recovery becomes a new forward change
or a broader external restore.

### 94. Operator-directed recovery trusts an unverified writer-exclusion claim

**Traceability:** original finding #99

**Severity:** High  
**Type:** Recovery-operability gap

Importing a checkpoint can replace the database lease row, so safety requires
an external maintenance window. `--writers-excluded` is only the operator's
assertion that every Duo writer, updater, package manager, and automation path
has been stopped (`docs/guides/recovery.md:24-37`, `:207-217`).

**Agency impact:** the default recovery path cannot establish its own key safety
precondition. The agency must inventory and fence every writer correctly during
an incident.

### 95. Signed recovery exposes one active receipt, not rollback history

**Traceability:** original finding #100

**Severity:** High  
**Type:** Recovery-retention boundary

The rollback authority is a single-active-generation state machine. Its catalog
is not a log, older signed checkpoints are not selectable, and the active
receipt has no creation timestamp from which to calculate age
(`docs/guides/recovery.md:39-63`).

**Agency impact:** Duo's strongest recovery profile does not provide a
multi-point rollback history or a reliable checkpoint-age view. Agencies still
need an external backup catalog and retention/audit system.

### 96. PDF, SVG, audio, and video attachments are categorically refused

**Traceability:** new finding on current `main`

**Severity:** Blocker for affected sites  
**Type:** Media boundary  
**Scenario:** A client library contains ordinary documents, vector artwork,
podcasts, or video.

The repository format presents attachments as portable authored entities with
content-addressed binaries (`spec/repo-format.md:108`). The implementation,
however, admits only four exact raster families—GIF, JPEG, PNG, and WebP—plus
generic non-media MIME types. `MediaPayloadAuthority::kindFor()` rejects every
other `image/*`, every `audio/*`, every `video/*`, and `application/pdf` with:

```text
duo: media payload selects an unbounded Core image, audio, video, or PDF metadata branch
```

The closed predicate and refusal are in
`agent/src/Kernel/MediaPayloadAuthority.php:497-520`, and the same classification
runs while capture establishes the attachment's media witness (`:579-595`). An
SVG is rejected by the `image/*` branch even when WordPress permits it through a
plugin.

**Agency impact:** a single PDF brochure, SVG logo, audio episode, or hosted
video inside managed attachment state can prevent capture and initialization.
The agency must delete/exclude common media or build a new bounded product path.

### 97. Media transfer has hard 256 MiB per-file and 1 GiB aggregate ceilings

**Traceability:** new finding on current `main`

**Severity:** Blocker for media-heavy sites  
**Type:** State-transfer boundary  
**Scenario:** A client has one large original or an attachment library above
one gibibyte.

`MediaPayloadAuthority` sets `MAX_FILE_BYTES` to 268,435,456 and
`MAX_AGGREGATE_BYTES` to 1,073,741,824
(`agent/src/Kernel/MediaPayloadAuthority.php:30-44`). The aggregate counter
throws once all represented media exceed 1 GiB (`:425-432`), and any individual
payload above 256 MiB throws an exact refusal (`:768-774`). The limits are
absolute before current PHP memory headroom narrows them further.

**Agency impact:** very large PNG/WebP originals, high-resolution production
assets, and otherwise ordinary mature media libraries cannot be represented as
one portable Duo state. There is no chunking, external-blob reference, or
partial-library workflow that preserves the full authored attachment set.

### 98. Large builder/configuration payloads exceed fixed option and metadata bounds

**Traceability:** new finding on current `main`

**Severity:** Blocker for affected builder/plugin sites  
**Type:** State-shape boundary  
**Scenario:** A page builder or plugin stores a large serialized document in an
option or metadata row.

Exact option capture rejects a value above 16 MiB and namespace discovery
rejects either a value above 16 MiB or aggregate discovered option bytes above
128 MiB (`agent/src/Capture/OptionsCapture.php:20-25`, `:466-473`, `:545-592`).
The shared post/term/user metadata reader independently rejects a value above
16 MiB, more than 100,000 rows for one owner, or more than 64 MiB for that
owner (`agent/src/Kernel/MetaRows.php:15-20`, `:67-98`). These are capture
refusals, not display truncation.

**Agency impact:** a valid WordPress site can fit its database schema while a
large page-builder document, form definition, catalog configuration, or dense
metadata owner cannot enter Duo state at all. Splitting those plugin-owned bytes
usually requires application-specific redesign, not an operator flag.

### 99. Onboarding promises Git LFS for media but neither configures nor checks it

**Traceability:** new finding on current `main`

**Severity:** High for sites with material media  
**Type:** Repository-operability defect  
**Scenario:** An agency follows the generated initialization handoff for a real
site repository.

The architecture and normative format say real site repositories store
content-addressed media through Git LFS (`DESIGN.md:69`,
`spec/repo-format.md:24`). The onboarding transaction creates or repairs only
`.gitignore`; its complete required list has no `.gitattributes` or LFS rule
(`agent/src/Init/InitRepositoryBoundary.php:259-326`). The generated next step
then tells the operator to `git add ... media` and push it
(`cli/src/Onboarding/Init.php:288-307`). No product check invokes `git lfs`,
validates an LFS filter, or verifies that media paths are LFS pointers.

**Agency impact:** the supported handoff can silently commit every media binary
to ordinary Git, making clone/fetch history grow without bound and potentially
breaching repository-host limits. Each agency must invent, deploy, and audit an
LFS policy outside the onboarding path that claims it.

### 100. There is no supported de-adoption or client-offboarding transaction

**Traceability:** new finding on current `main`

**Severity:** High  
**Type:** Lifecycle/operational gap  
**Scenario:** A pilot fails, a client leaves the agency, or the operator must
remove Duo without damaging the site.

Adoption installs an MU loader, agent directory, manifest library, recovery
control, keys/revocations, repository seed, and local control state as one
ownership-checked transaction (`cli/src/Onboarding/Adopt.php:455-488`). The
public CLI's complete command list provides `connect`, `onboard`, `adopt`,
`init`, preview/environment reap, and recovery pruning, but no `unadopt`,
`disconnect`, or uninstall verb (`cli/duo:299-400`). The removal helpers inside
`Adopt` are rollback machinery for a failed install; they are not exposed as a
later offboarding contract.

**Agency impact:** adoption is not operationally reversible through a supported
workflow. An agency must manually identify and remove privileged control-plane
artifacts, decide what repository and recovery evidence to retain, and prove it
did not delete pre-existing site state—the exact ownership problem the adoption
transaction otherwise handles.

## Portfolio conclusion

The current 100 findings keep the decision at **no-go for broad agency
adoption**. A pilot is viable only for a small single-site installation inside
the exact platform and adapter windows, with a constrained media/configuration
shape and explicit external systems for preview hosting, secrets, inventory,
Git LFS, recovery history, and eventual offboarding.
