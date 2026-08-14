# Thread 4 — Capability, policy, adapters, providers, and evidence

*Status: approved for P0 inventory/test-only work; production execution follows Platform P0.*

*Depends on: [Thread 0](thread-0-foundation.md).*

*Primary rule: modularize today's truth machinery without upgrading it into the target product model.*

## Goal

Separate declaration grammar, policy loading/query, adapter discovery,
provider negotiation/execution, capability projection, and evidence validation
behind stable facades.

The output of this round is a maintainable implementation of the current
behavior after Thread 0's conservative claim/safety corrections.
It is not registry v3, a six-dimensional capability decision, a per-site
projection, or site certification. Those enhancements need new evidence and
governance after the refactor.

## Why this thread exists

- `AdapterSources.php` is roughly 3,600 lines and combines filesystem/source
  discovery, plugin-bundled inputs, trust, grammar, certification, provenance,
  diagnostics, rendering, and frozen snapshots.
- `Policy.php` is roughly 2,900 lines despite recent useful resolver
  extractions. Loading, validation, indexing, query, and writing remain tightly
  coupled.
- `Providers.php` is roughly 2,860 lines and combines provider catalog,
  negotiation, invocation, scoped-operation state, receipt binding, budgets,
  and redaction.
- `CapabilityRegistry.php`, manifest dispositions, the generator, generated
  docs, and signed evidence form one authority chain without explicit module
  boundaries.
- Current vocabulary collapses concepts that the finalized spec separates.
Copying those fields into new types as if they had target semantics would make
unsupported claims.
- The evidence closure includes broad source/tool trees, so ordinary refactors
  invalidate many or all capability subjects.

## Owned surface

Thread 4 owns:

```text
manifests/**
scripts/capability-registry.php
scripts/adapter-certification.php
scripts/evidence-impact
scripts/evidence-staleness-check
docs/capabilities.md
docs/compatibility-baseline.json
README.md capability marker block
cli/src/AdapterCatalog.php
cli/src/AdapterDraft.php
cli/src/AdapterObservation.php
cli/src/ManifestValidate.php
cli/src/ArtifactTrust/**
sandbox/conformance/entries/**
sandbox/conformance/seeds/**
sandbox/conformance/postdeploy/**
sandbox/conformance/checks/**
sandbox/conformance/run.sh
sandbox/conformance/asserts.sh
sandbox/conformance/manifests.json
sandbox/conformance/artifacts.lock.json
sandbox/bin/fetch-artifact.sh
sandbox/bin/artifact-cache-fetch.sh
sandbox/bin/artifact-archive-root.sh
sandbox/bin/subject-certification-bundle.php
future sandbox/certification/** source and definitions
```

`sandbox/certification-bundles/**` is disposable/generated output, not an owned
source tree and never a durable evidence import.

Its initial `agent/src` allowlist is:

```text
ActionProviderGrammar.php
AdapterCertification.php
AdapterContractGrammar.php
AdapterObservationProjector.php
AdapterRegistry.php
AdapterSources.php
ArtifactPolicyIdentity.php
AttributeGrammar.php
CapabilityRegistry.php
CodeConfigGrammar.php
ContentAttributeRuleResolver.php
CrossManifestGuards.php
DeletionCapabilityResolver.php
DiscoveryGrammar.php
DynamicOptionResolver.php
ExactOptionResolver.php
FieldGrammar.php
ManifestDispositions.php
ManifestGrammar.php
ManifestValidator.php
NativeActionCatalog.php
OptionGrammar.php
OptionNameReferenceResolver.php
OptionNamespaceResolver.php
OptionReferenceGrammar.php
PinResolver.php
Policy.php
PolicyLoadFinalizer.php
PolicyRuleResolver.php
PolicyWriter.php
PostTypeGrammar.php
PostTypeRelationResolver.php
ProviderSdk.php
Providers.php
ReferenceKeyspaceGrammar.php
ReferenceKindGrammar.php
ReferenceRules.php
ReferenceScopeClassifier.php
ReferenceShapeGrammar.php
ScopeGrammar.php
ScopedCertificationBundle.php
SitePolicyValidator.php
SubKeyGrammar.php
TableDeclarationResolver.php
TableGraph.php
TableSchema.php
TargetRuntimeInspector.php
TaxonomyDescriptionReferenceResolver.php
TaxonomyGrammar.php
TaxonomyKeyspaceResolver.php
TaxonomyObjectTypeOptionResolver.php
TaxonomyPatternResolver.php
UserMetaGrammar.php
VersionRange.php
WidgetTypeResolver.php
WordPressTargetRuntimeInspector.php
```

Thread 3 owns the agent-side `AdapterObservation.php` collector/WordPress
adapter and alone wires agent commands. Thread 4 owns only the inert
closed-observation-to-catalog projector behind its facade. Thread 2 alone wires
host commands.
Thread 5 consumes policy/provider facades and does not edit their implementation.
Thread 1 owns test/conformance runner mechanics; Thread 4 owns the semantic
conformance definitions and assertions for its subjects.

`sandbox/conformance/run.sh` remains a Thread 4-owned semantic composition
root. It delegates reusable process, workspace, logging, timeout, and sharding
mechanics to Thread 1-owned `sandbox/lib/**`; neither thread owns sections of
the other's file.

The Thread 0 ledger assigns the existing `cli/src/ArtifactTrust/**` prefix and
future `Capability/`, `Policy/`, `Adapter/`, `Provider/`, and `Evidence/`
module prefixes exclusively to this thread. The allowlist above covers the
current flat tree only. `AdapterObservation.php` is deliberately absent: it is
the Thread 3 collector; `AdapterObservationProjector.php` is the Thread 4
closed, inert projector.

### P0 execution lock and authoritative result

Before Thread 4 changes production code, Thread 1's Platform P0 schema,
validator, and serial runner must be integrated; all Thread 2–5 owner-authored
test-only catalog/component-profile fragments must land; and Thread 1 must
generate the aggregate and activate every nonempty profile. Thread 4 then
rebases onto that completed P0 and passes its profile. Until then it may
inventory and add only its exclusive test-only fragment; it does not edit
production surfaces or compensate by copying another thread's implementation.

`make test-component COMPONENT=capability-policy-evidence` is the focused
offline development gate. The authoritative Thread 4 gate is
`make thread-4-gate`; its retained structured result is
`artifacts/test-results/thread-4/result.json`. The evidence-staleness gate uses
one of the four result states and records `non_releasable` as release-eligibility
metadata/branch state; it must name the exact reviewed impact set and cannot
waive frozen-candidate recertification.

## Target internal shape

```text
Declarations
  -> grammar/parser
  -> validator
  -> immutable PolicySnapshot + indexes
  -> query facade

Adapter inputs
  -> source discovery
  -> provenance/trust validation
  -> current certification validation
  -> AdapterCatalog

Provider declarations
  -> catalog/negotiation
  -> invocation/receipt boundary

PolicySnapshot + AdapterCatalog + dispositions + evidence
  -> CurrentCapabilityProjection
  -> current registry bytes/docs/API
```

`CurrentCapabilityProjection` is deliberately named as a compatibility model.
It must not be described as the finalized effective site projection.

## Deliverables

### 1. Freeze current authority outputs

After Thread 0's safety disposition, add golden fixtures for:

- policy load/query results across representative manifests;
- adapter source precedence, pins, trust tiers, refusals, and export snapshots;
- provider negotiation, invocation, receipt binding, and redaction;
- capability registry JSON and generated Markdown/README block;
- archived v1 scoped-certification bundle fixture bytes and verifier
  acceptance/rejection outcomes;
- current version/disposition/evidence mismatch behavior.

Fixtures distinguish reviewed declarations, evidence records, dispositions,
and generated claims. A test or manifest cannot manufacture a certified claim.
The foundation's current projection-pack v1 proves only linkage to reviewed
bytes; it does not yet carry generated registry/docs/claim bytes. Its archived
fixtures therefore prove v1 linkage/signature acceptance and rejection. The
separate versioned projection contract below supplies recomputation and
byte-comparison fixtures before a generated projection can be used as a cache.
The foundation source/recovery correction intentionally leaves all affected v1
subjects stale and this branch non-releasable; no durable evidence is
regenerated merely to make this thread green.

### 2. Policy pipeline

Keep `Policy::load()` and current query methods as compatibility facades while
extracting:

- source reader;
- grammar/parser;
- schema and cross-manifest validator;
- load finalizer;
- immutable normalized policy snapshot;
- indexes by surface/entity/field/action/provider;
- focused query services;
- policy writer/publication boundary.

Existing resolver classes are reused. Avoid creating a second normalized model
beside `Policy`; migrate one query family at a time and delete the old branch
after equivalence tests.

`CodeConfigGrammar` consumes Thread 3's pure code config/descriptor contract.
It must not depend on Thread 5's target-mutation `Code` facade.

Policy parsing performs no WordPress or provider side effects. Consumers receive
an immutable snapshot or current facade, not mutable public arrays they can
silently amend.

`AtomicFilePublisher` is a Thread 3-owned compatibility seam. `PolicyWriter`
and `Policy::set_rule()` continue to delegate authoritative single-file
publication to it; Thread 4 neither reimplements nor weakens same-directory
exclusive staging, complete write/flush/fsync, atomic rename, existing-mode
preservation, or the injected pre-rename old-or-new-visible failure contract.

### 3. Adapter-source pipeline

Keep `AdapterSources` as the compatibility facade while extracting:

- source-location discovery;
- shipped, site-local, and plugin-bundled candidate readers;
- path/name/containment validation;
- manifest grammar validation;
- pin and precedence resolution;
- trust/provenance evaluation;
- current adapter-certificate verification;
- diagnostics and public redaction;
- frozen snapshot encode/decode.

Each source reader returns an inert candidate. Discovery never grants
readiness, mutation authority, or certification. Plugin/theme code is not
loaded merely to discover a declaration.

Current “site adapter certification” retains its wire/API name for
compatibility but is treated architecturally as adapter-package evidence. The
refactor must not present it as the finalized customer-organization site
attestation. Internal types and new human-facing documentation use “legacy
adapter-package evidence”; only compatibility serializers preserve the old
wire label, and no output renders it as target `Site-certified`.

### 4. Provider boundary

Split the current facade into explicit roles while preserving its public API:

- provider declaration/catalog;
- capability/packaging negotiation;
- selected-action requirements;
- invocation request builder;
- process/WordPress adapter;
- receipt validator/redactor;
- scoped-operation state store;
- reconciliation and retry decision;
- budget enforcement.

`TargetRuntimeInspectionPort`, `WordPressTargetRuntimeInspector`, and
`VersionRange` are Thread 4 provider/capability leaves. Offline policy and
registry loading has no target facts and must not fabricate a missing-provider
problem; only a target-facing negotiation path may lazily load the WordPress
adapter to inspect identity/capabilities without invoking a provider action.

Thread 5 calls one provider execution facade; it does not reach into catalog,
receipt, or state-store internals. Provider output remains untrusted until the
current validator accepts it.

Thread 4 owns per-provider invocation idempotency, receipt validation/storage,
and provider-state reconciliation inside the facade. Thread 5 owns ordering and
resume of a mutation's multi-provider/action batch and consumes only reviewed
provider results; it does not implement a second provider receipt state machine.

Do not reinterpret operator-editable environment/provider declarations as the
final spec's containment evidence, recovery proof, or authorization.

### 5. Current capability projection

Separate the current registry into:

- input readers;
- blocker/evidence-current evaluation;
- operation projection;
- current status/verdict calculation;
- deterministic row ordering;
- JSON serialization;
- human/generated documentation rendering.

Keep the post-safety-gate format and meaning byte-compatible. Internally expose
a `LegacyCapabilityVerdict`, never a generic `ready` property. Existing
`certified|experimental|excluded`, current verdicts, and the `ready` boolean at
the compatibility serializer are legacy outputs, not aliases for target
technical readiness or certification provenance.

The thread must not:

- map `certified + current` mechanically to target `Ready`;
- derive `Site-certified` from current adapter evidence;
- derive handling solely from state class;
- map legacy `restorable`/`reversible` effects to stronger recovery semantics;
- invent customer-operation dependency maps;
- treat an untested version inside a declared range as evidence-backed.

Those decisions require the enhancement round and, where applicable,
requalification.

Byte compatibility does not preserve a confirmed false current claim. Before
fixtures freeze, Thread 0 and this owner conservatively resolve or remove:

- version-range claims broader than exact evidence;
- unresolved generated evidence references, including the current literal
  `bundle.environment_summary.wordpress` output;
- any Experimental/unproved operation executable outside the foundation's
  production quarantine.

General compatibility predicates and registry v3 remain deferred; conservative
claim reduction does not.

### 6. Evidence and dependency boundary

Separate evidence concerns into:

- subject/input identity;
- dependency path enumeration;
- content hashing;
- bundle assembly;
- immutable publication;
- certificate/signature verification;
- disposition review;
- generated claim projection.

Expose a read-only evidence-impact report used by Thread 0 and CI. Implement
Thread 0's approved closure-v2 migration before built artifacts become the
certified runtime: bind the exact runtime/declaration payload and build
definition, recovery runtime, generated loaders/shared package, and exact
test-plan, harness, oracle, helper,
PHP, WordPress, database, container-image, dependency, provider/configuration,
environment-fingerprint, and execution-receipt identities consumed by the
evidence. Archived v1 bundles remain parseable and cryptographically verifiable
for historical audit; a v1 bundle with incomplete dependency closure cannot
keep a claim current. Do not narrow the closure merely to reduce
recertification cost without proving that every runtime and evidence dependency
remains bound.

Every environment/provider/configuration identity uses Thread 0's canonical
`RedactedEvidenceIdentity` schema and runtime validator. Its public fields are
the reviewed profile registry only; other identity is a bounded `vault:`
reference or domain-separated `hmac-sha256:` keyed binding. Evidence contains
no credential, raw secret/PII, machine-local path, raw low-entropy value, or
unkeyed digest of such a value. Closure/evidence assembly validates these
identities before publication; the host trust verifier treats supplied evidence
inputs as opaque bytes after their exact bytes are bound. Redaction and
dictionary-guess resistance have adversarial fixtures.

Publish two physically separate outputs defined by Thread 0. The current
detached `duo-review-envelope/v1` is closed and Ed25519-signed: its exact fields
are `format`, `authority_id`, `key_id`, `payload`, and `signature`. Its closed
`duo-review-bundle/v2` payload binds the target-install/host digests, the
host/agent/recovery protocol tuple, and exact declaration-payload and
evidence-input digest maps. The independently pinned `ReleaseSelection`
supplies the accepted authority/key pair and public key. Authority/key labels
without that signature confer no trust. The payload therefore does not
currently carry a separate approving-principal or provenance-class field. If
either stronger field is required, Thread 0 must first amend the
review-envelope/payload schema, trust policy, acceptance rules, and fixtures;
Thread 4 must not add it unilaterally.

The current `duo-projection-pack/v1` is also closed linkage metadata only:
`format`, `review_envelope_sha256`, and `reviewed_payload_sha256`. It contains
no generated registry/docs/claim bytes, and v1 verification establishes those
links rather than recomputing a projection. Before a projection carries
generated bytes or is accepted only after byte comparison with a recomputation,
Thread 0 must approve a versioned projection-pack contract, acceptance policy,
and migration fixture. Thread 4 then implements that stronger contract while
preserving v1 audit verification. Until then, a packaged or signed projection
is never authority and is not evidence of generated claim bytes.

Thread 1 installs those outputs under separate `overlay/review/**` and
`overlay/projection/**` roots, outside immutable runtime/declaration payloads,
and binds them in the target release set. Thread 4 exports the runtime lookup
and verification facade for those roots plus a pure host-safe implementation
under `cli/src/ArtifactTrust/**`. That implementation consumes only bytes and
versioned contracts, imports no WordPress/agent code, and is packaged and
closure-bound in the host artifact by Thread 1 for Thread 2 adoption. Ordinary
build/test jobs never hold the approval signing key. Legacy third-party or
harness signatures keep their literal provenance and cannot be relabeled as
current-platform review. Neither bundle, projection, nor release manifest can
confer authority on itself. `ReleaseSelection` itself is a Thread 2-owned
HostContracts leaf. Thread 4 consumes its independently pinned
family/set/host/protocol/key inputs through the host-safe verifier and does not
extend that closed selection record.

Thread 4 owns an exact source-to-artifact classification fragment for every
tracked file under `manifests/**`. Reviewed declarations may enter
`payload/declarations/**`; dispositions/evidence enter only
`overlay/review/**`; generated capability registry/claim bytes enter only
`overlay/projection/**`. An unclassified file or membership in two classes
fails both the ownership and distribution gates.

Add a synthetic closure regression that changes one path under each deployed
tree—agent, host CLI, recovery, generated loader/shared package, build
manifest—and one manifest-specific/conformance input, then asserts the exact
affected subjects. Catalog metadata describes tests but never decides evidence
authority; the reviewed closure follows helper dependencies transitively.

Working branches may create disposable candidate bundles for tests but cannot
import durable evidence. Only the frozen reachable candidate and current
evidence/disposition review process produce the final imported records.

Thread 1 may optimize non-authoritative display paths, but certification and
claim freshness always consume exact bytes or a content-addressed artifact
whose actual bytes are independently verified against its immutable manifest.
The manifest alone is metadata, not authority.

### 7. Facade and dependency enforcement

Publish stable internal facades for:

- `PolicySnapshot`/current policy queries;
- adapter catalog/current provenance;
- provider negotiation and execution;
- current capability projection;
- evidence-impact reporting.

Names must distinguish current compatibility semantics from future product
contracts. Agent/capture and mutation modules depend on these facades, not
`Policy.php` or `Providers.php` internals.

## Interfaces exported

- Current-policy load/query facade and immutable normalized snapshot.
- Inert native-action catalog/grammar facade consumed by policy loading.
- Adapter catalog/source/provenance facade.
- Inert closed-observation/catalog projector consumed by Thread 3's agent-side
  collector.
- Provider negotiation/execution facade.
- Current capability projection and deterministic renderer.
- Evidence dependency/impact report.
- Pure host-safe review-bundle/projection verifier consumed by Thread 2's
  `ReleaseSelection` path.
- Thread 4-owned semantic conformance catalog/component-profile fragment,
  consumed through Thread 1's validated generated aggregate.

## Constraints

- No registry-format or generated-claim semantic change after the foundation
  safety/claim disposition.
- No new Ready, provenance, handling, containment, recovery, or customer
  operation output.
- No new site-certification or qualification behavior.
- No declaration/test self-certification path.
- No provider/adapter code execution during inert discovery.
- No hand edits to generated registry/docs.
- No weakening of exact stack/evidence checks, redaction, path containment,
  signature validation, or force-hatch reporting.
- Any genuine incorrect current claim discovered during characterization uses
  Thread 0's stop-the-line process and is reduced/quarantined before merge, not
  locked into a fixture or hidden by a convenient remapping.

## Required verification

At minimum, run catalog-selected suites for:

- policy grammar, validation, resolvers, and writers;
- adapter source precedence, containment, trust, snapshots, and certification;
- provider declaration, negotiation, invocation, scoped state, receipts, and
  redaction;
- capability generation, generated-doc agreement, and release gate;
- scoped bundle creation/verification and adversarial inputs;
- every conformance subject affected by the evidence-impact report;
- the complete offline corpus before integration.

Use `make test-component COMPONENT=capability-policy-evidence` for the offline
development gate. `make thread-4-gate` is the authoritative Thread 4 gate and
must retain `artifacts/test-results/thread-4/result.json`. Thread 4 owns that
catalog/component-profile fragment; Thread 1 validates and aggregates it. Live
conformance/certification profiles remain
separately classified and authority-controlled and require all of Thread 0's
provisioning/data/credential/egress/effect/output containment bindings.

For fixed synthetic inputs, archived v1 bundle fixture bytes and verifier
acceptance/rejection outcomes match their post-safety baseline. Closure v2 has
separate deterministic goldens and one reviewed format/authority migration
diff; registry schema and public claim semantics remain equivalent. Production
bundle/evidence digests are expected to change with bound source; they change
only during Thread 0's frozen-candidate recertification and receive a reviewed
semantic diff.

## Done means

- `Policy`, `AdapterSources`, `Providers`, and `CapabilityRegistry` are stable
  facades over focused modules rather than multi-domain implementations.
- Consumers use exported facades and dependency checks enforce that rule.
- Registry/docs serializers remain deterministic and schema/meaning compatible
  for fixed post-safety inputs; production evidence is regenerated once for the
  frozen candidate, so its source-bound digests are expected to change.
- Evidence dependency impact is inspectable before merge.
- Current adapter evidence is not confused with the future per-site
  application attestation.
- New internal consumers cannot access the legacy `ready` boolean except
  through the compatibility serializer.
- No new product capability claim was created by the refactor.

## Explicitly deferred

- Target capability value objects/enums and registry v3.
- Independent readiness/provenance/handling/effect projection.
- Customer-operation dependency maps.
- Site application contract, site attestation, and effective projection.
- Qualification/requalification and exact compatibility predicates.
- New effect and recovery semantics.
- Public Assess rendering or product authorization decisions.
