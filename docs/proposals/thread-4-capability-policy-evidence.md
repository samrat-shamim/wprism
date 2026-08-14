# Thread 4 — Capability, policy, adapters, providers, and evidence

*Status: proposed for owner approval.*

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
future cli/src/ArtifactTrust/**
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
TaxonomyDescriptionReferenceResolver.php
TaxonomyGrammar.php
TaxonomyKeyspaceResolver.php
TaxonomyObjectTypeOptionResolver.php
TaxonomyPatternResolver.php
UserMetaGrammar.php
WidgetTypeResolver.php
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

The Thread 0 ledger assigns future `Capability/`, `Policy/`, `Adapter/`,
`Provider/`, and `Evidence/` module prefixes exclusively to this thread. The
allowlist above covers the current flat tree only.

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
They also prove that a generated projection is rejected when its independently
recomputed bytes differ, even if that projection was packaged or signed.

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
redacted schema. Evidence contains no credential, raw secret/PII, machine-local
path, raw low-entropy value, or unkeyed digest of such a value; it binds
reviewed secret-reference IDs or domain-separated keyed digests where
necessary. Redaction and dictionary-guess resistance have adversarial fixtures.

Publish two physically separate outputs defined by Thread 0. The detached
review bundle contains only current reviewed authority input—reviewed
disposition or applicable current platform certification record (never a target
site attestation), immutable evidence identities, approving principal,
provenance class, trust-root key ID, and exact payload bindings. The
review payload is carried in a closed Ed25519-signed envelope; authority/key
labels without a signature verified by the independently pinned public key
confer no trust. The
deterministic projection pack contains generated registry/docs/claim bytes and
is always recomputed and byte-compared from its exact reviewed inputs before
use. A packaged or signed projection remains a cache, never authority.

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
confer authority on itself.

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
thread gate. Thread 4 owns that catalog/component-profile fragment; Thread 1
validates and aggregates it. Live conformance/certification profiles remain
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
