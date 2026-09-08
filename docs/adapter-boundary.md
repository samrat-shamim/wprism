# Engine core, adapter packages, and the WordPress SDLC — boundary doctrine

*Owner ruling — 2026-08-09; promoted to canonical documentation at this path
2026-08-21, its § numbering load-bearing because six shipped source sites cite
it by name. Refreshed for the product-first program tracked in
Linear's [Engine/Adapter Boundary & WordPress SDLC](https://linear.app/duotronic/project/engineadapter-boundary-and-wordpress-sdlc-c0fdf8775799)
project. The project description and its “Fresh product and architecture
roadmap” are the companion planning authority. Historical issue sequencing is
not authoritative.*

This document defines the durable boundary. It intentionally avoids transient
line numbers, branch names, audit counts, and pull-request sequencing. If an
issue proposes a different boundary, stop and obtain an owner ruling rather
than silently weakening this doctrine.

## Product outcome

WPrism should be the ordinary development and operations workflow for WordPress:
one understandable path for code, authored state, media references, lifecycle
hooks, previews, refresh/rebase, promotion, convergence, and rollback.

The engine succeeds by understanding WordPress as a platform while letting the
ecosystem own extension semantics. Supporting another plugin must not require
adding its name, schema, or business rules to engine core.

## Partition first: code and state are separate halves

The user may promote code and state as one release, but the implementation must
keep their contracts and identities explicit.

### Code half

The code half owns:

- immutable plugin, theme, MU-plugin, and approved WordPress-content bytes;
- code descriptors and code revision identity;
- safe target materialization and ownership-aware pruning;
- plugin/theme dependency and compatibility checks;
- lifecycle ordering against the code that will actually run; and
- exact code rollback or restoration.

### State half

The state half owns:

- authored WordPress entities and relationships;
- environment-neutral references and media identity;
- semantic planning, drift, conflicts, and deletion authority;
- classification of authored, generated, environment, secret, and runtime
  state;
- materialization of canonical authored state; and
- state convergence and rollback evidence.

### Typed promotion bridge

A promotion may bind an exact code revision and an exact state artifact under
one promotion identity. That binding coordinates the operation; it does not
collapse either identity into the other.

The bridge may carry typed lifecycle and state-handoff evidence needed to
sequence:

1. checkpoint;
2. code stage/materialization;
3. lifecycle retirement and activation;
4. code finalization;
5. state apply and generated effects;
6. convergence verification.

It must not hide code writes inside state apply, make state identity depend on
code payload bytes, or treat lifecycle state as filesystem materialization.
Each half remains independently inspectable and truthfully reports its own
drift, staleness, failure, and rollback status.

## The five extension surfaces

### 1. Engine core

`agent/src`, `cli/src`, and `recovery/` ship generic mechanisms. Engine core
may know WordPress core schema and lifecycle semantics because WordPress is the
platform. It must not:

- dispatch on a plugin or theme name;
- encode a third party's pricing, caching, migration, schema, or rebuild rules;
- assume a data shape merely because one plugin happened to use it first; or
- execute unvalidated PHP, shell, or WP-CLI command strings supplied by a
  manifest.

Engine responsibilities include capture, validation, planning, conflict
detection, scoped materialization, lifecycle orchestration, effects, receipts,
recovery, and verification.

Untrusted PHP serialization also crosses an engine boundary. All engine-owned
raw decodes use `SerializedDataPreflight` before PHP's decoder. The class filter
alone is insufficient: PHP enum records ignore `allowed_classes` and can invoke
an autoloader before an outer value check. The preflight distinguishes actual
`E` records from the same bytes inside length-framed strings; ordinary class
records remain subject to the existing disabled-class filter. `PlainData`
continues to refuse objects, references, recursion and noncanonical framing.
The shared regression exercises authored-option capture and the review,
delete-guard and native rewrite readers without executing an autoloader.

### 2. Declarative manifest

A manifest describes facts that can be represented as data:

- owned entities, fields, options, tables, and code components;
- authored/generated/environment/ignored classification;
- identity and reference paths;
- dependency and scope-closure edges;
- deletion guards and cascades;
- lifecycle and version requirements;
- generated effects and required capabilities; and
- provider identity when executable semantics are required.

Manifests are validated, versioned data. Loading a manifest does not grant it
arbitrary executable privileges. Generated manifest drafts are inert until a
human ratifies them; they never infer destructive authority or secret/PII
capture.

### 3. Structured native action

A native action is a closed, versioned operation implemented by generic engine
code. It has structured inputs, declared effects, bounded scope, receipts, and
verification. It is appropriate only when the operation has the same semantics
across plugins.

Plugins cannot add arbitrary native-action names or smuggle executable strings
through action arguments. A genuinely plugin-specific operation belongs in a
provider.

### 4. Executable provider behavior

A provider supplies executable semantics that remain plugin-specific. Two code
ownership paths share one engine-owned negotiation and receipt contract:

- `source: "plugin"` is loaded from the installed plugin and independently
  advertises identity and capabilities. This is the preferred cooperation path.
- `source: "manifest"` ships only the irreducible behavior module with the
  adapter. A v3 manifest declaring `manifest-provider-runtime/v1` carries the
  identity and capability contracts as data; engine core owns their validation,
  advertising, dispatch, scoped receipt construction, recovery routing, and
  receipt-shape enforcement. The behavior module owns only plugin API calls and
  the value-level postcondition that proves them.

Manifest-shipped providers, interpreters, and regenerators all use one
engine-owned descriptor loader. Artifact identity is the sole path/class/hash
authority; the loader rechecks source identity, opcode-cache state, and
process-wide symbol provenance before executable bytes can run. An adapter may
not replace that boundary with a direct include or its own loader.

The provider contract must define:

- deterministic identity and compatible plugin/provider versions;
- capability negotiation before mutation;
- structured, bounded inputs and outputs;
- declared reads, writes, external effects, and required lifecycle context;
- batching, heartbeat, timeout, idempotency, retry, and failure semantics;
- receipts sufficient for recovery; and
- value-level verification, not command-success-only verification.

WPrism's provider SDK and manifest runtime may offer generic facilities such as
checked database reads, canonical codecs, scoped context, heartbeat, receipts,
dispatch, and error mapping. They must not become a second home for plugin APIs
or business rules.

A bootstrap-sensitive manifest provider may declare a site-scoped, idempotent
capability under `manifest-provider-fresh-process/v1`. The adapter still owns
only the plugin call and complete postimage projection. Core owns both fresh
WordPress boots, the fixed command and canonical transport, one deadline,
parent/descendant lifetime, persistent-cache fencing, frozen-policy and
executable identity checks, database read isolation, receipt comparison, and
recovery classification. Adapter-owned process launchers, command strings,
transaction orchestration, and transport parsers cross the boundary and are not
accepted.

Ten historical runtime files predate that rule. They are compatibility debt,
not SDK: `AdapterPackageValidator::LEGACY_RUNTIME_EXECUTION_DEBT` records each
exact package path, source SHA-256, finding set, and replacement boundary, while
the v2 `runtimeSdk()` no longer advertises `WpCliChildProcess`. The validator
accepts only those unchanged bytes and refuses a moved file, a changed hash, an
added finding, or the same machinery in any other runtime. Removal is staged by
the owning adapter's next recertification: move The Events Calendar and
WooCommerce transaction/DML code to the engine database session first; move the
six provider-owned child protocols to the manifest fresh-process runtime; and
replace WooCommerce's interpreter self-loader with a closed, digest-bound engine
probe. Each migration deletes its registry row rather than updating the hash.

Fresh execution is two-phase: one child mutates and projects, then a distinct
child invokes no provider mutation and observes the durable postimage through
the canonical `$wpdb` read-only boundary. That boundary is not a filesystem,
cache, network, or alternate-database sandbox. Durable option proof therefore
uses the SDK's exact bounded physical-row reader, never cache-backed
`get_option()`. Failure after the mutation child is conservatively visible as
recovery debt. Real-process tests must cover both process identities, cache and
identity drift, deadline/parent-death behavior, observer write refusal, exact
projection equality, and composition with every adapter sharing its declared
surfaces or lifecycle constraints.

### 5. Compatibility shim

An executable WPrism-owned shim is an exceptional, temporary bridge for an
ecosystem limitation. It must be quarantined outside engine decision logic,
named in capability diagnostics, version-bounded, and assigned a removal path.
It is never the default adapter-authoring model and never masquerades as a
declarative manifest or plugin-owned provider.

## Trust and capability are distinct

The trust decision depends on what is installed:

- a data-only manifest receives declarative authority only;
- a native action invokes reviewed generic engine behavior;
- a plugin provider is executable code trusted as part of the installed plugin;
- a manifest-owned behavior module is accepted by WPrism only after its
  digest-bound bytes are loaded and dispatched through the declared
  engine-owned contract; directly constructed, cloned, or unserialized runtime
  objects receive no provider-SDK database authority; and
- a compatibility shim is executable and explicitly reported as exceptional.

Manifest-owned PHP is trusted reviewed code, not adversarial code contained by
a language sandbox. The runtime SDK, exact-object authority and package
validator prevent accidental boundary expansion and make reviewed capabilities
explicit; they cannot prevent malicious PHP from using reflection, globals,
filesystem/network functions, or raw database handles. Defending against a
hostile adapter executable would require an out-of-process capability sandbox
and is outside the current platform architecture.

Certification is evidence about supported behavior, not a substitute for the
trust decision. Certified, exercised, uncertified, incompatible, missing, and
ambiguous capabilities must remain visibly different in `status`, `plan`, and
adapter diagnostics.

Those six words are this doctrine's, and the shipped vocabulary is smaller by
design — a status word minted to match prose would read as reviewed evidence
next to `package/disposition.json`, which is precisely the substitution the
certification separation exists to refuse (`agent/src/Adapter/AdapterSources.php`
states the same rationale for the fourth word `uncertified`). So the mapping is
stated here instead of being invented in code:

> **The apparatus this table was originally written against is gone; the
> distinction it enforces is not.** The teardown train (#477–#480) removed the
> certification-evidence machinery — the generated
> `manifests/capabilities/registry.json`, its evidence record with the
> `current`/`candidate` `evidence.status` field, and the per-subject
> certification bundles — and renamed the class that reports capability.
> `CapabilityRegistry` no longer exists (`cli/src/Adapter/AdapterCertify.php:996`:
> "There is no CapabilityRegistry.php to require any more"); the reporter is
> `AdapterRegistry::report()` in `agent/src/Adapter/AdapterRegistry.php`,
> emitting `wprism-capability-report/v1` (`:57`), and the reviewed claim source is
> the hand-authored capsule `package/disposition.json`. The blocker code for a name
> with no reviewed entry is `missing_disposition_entry` (`:392`), not
> `missing_registry_entry`. Every row below is restated against what ships
> today. The doctrine above is unchanged: it never required a particular
> reporter, only that the six states stay visibly distinct.

| Doctrine word | Where it is visibly reported today |
|---|---|
| certified | the adapter capsule's `package/disposition.json` status `certified` + a `certified` verdict from `AdapterRegistry::report()`; `wprism adapter list` disposition column |
| exercised | not a status. The evidence facts behind it are printed by `wprism adapter inspect` under "verification (the facts that exist, not a scale)": `evidence.bundle_schema`, `plugin_execution.status` (`verified`/`unverified`/`not-a-product-claim`), and each cited test **id and nothing else** — a verdict word there would be a result that process did not produce (`cli/src/Adapter/AdapterCatalog.php:1052-1063`). The retired `evidence.status` (`current`/`candidate`) conjunct is gone with the apparatus that set it (`cli/src/Transport/CodeDeploy.php:191-194` records why keeping it would now refuse every claim) |
| uncertified | the fourth status word, carried by an out-of-tree adapter's synthesized provenance record; blocker code `adapter_source_uncertified` |
| incompatible | two separate code sets, deliberately not merged: a **certification** verdict from `AdapterRegistry::report()` whose reason names the mismatch (`plugin_version_mismatch`, `plugin_not_active` — the two `target_reasons()` raises today, `agent/src/Adapter/AdapterRegistry.php:559-579`), and a **negotiation** problem row from `Providers::diagnose()` (`outside_version_range`, `identity_mismatch`, `contract_shape`, `malformed_capability`, `invalid_capability_args`, `non_idempotent_capability`). The five platform-axis codes this row used to name — `wordpress_version_mismatch`, `php_version_mismatch`, `database_version_mismatch`, `theme_version_mismatch`, `revision_not_certified` — were raised against the generated evidence record's measured axes and went with it; `multisite_unsupported` survives only as an *init* refusal (`agent/src/Init/InitPlanner.php:340`), because topology is now judged once for the whole assessment rather than per surface (`cli/src/Contract/ProjectionVocabulary.php:219-235`) |
| missing | certification: `missing_disposition_entry` — `no reviewed disposition entry exists for '<name>'`. Negotiation: `missing_plugin`, `inactive_plugin`, `missing_plugin_provider`, `missing_capability`, `undeclared_provider`, `provider_code_unavailable` |
| ambiguous | refused rather than reported as a status — `AdapterSources` refuses ambiguous identity, shadowing, declared-name collisions, case-fold confusables, an unanchored plugin bundle, and two active plugins bundling one name at load, and `wprism adapter doctor` / `wp wprism adapter-survey` report the same conditions as refusal rows with codes `ambiguous_identity`, `shadows_shipped`, `name_collision`, `case_collision`, `plugin_anchor_mismatch`, `source_collision`, `source_unreadable`. One ambiguity is deliberately NOT refused: a plugin-bundled name a shipped or site definition already answers to is RESOLVED by the source precedence `shipped > site > plugin` and reported as an installed-but-not-loaded row naming its winner. Ambiguity is refused where the operator authored both sides; where a third party's update created it, resolving it loudly beats taking the site down (see spec/repo-format.md, "Plugin-bundled adapters") |

The requirement the table serves is unchanged: each of those states must be
distinguishable wherever capabilities are reported. It is the *word* that is
not minted, not the distinction.

An unsupported or unverifiable capability fails before destructive writes.
WPrism reports the missing capability, responsible adapter/provider, compatible
versions, and remediation path rather than guessing or silently degrading.

## Generated state belongs to the plugin's semantics

WPrism distinguishes authored truth from generated tables, caches, indexes, and
other projections. The manifest declares when generated effects are required;
the plugin's provider or a generic native action performs them.

The engine owns orchestration, effect boundaries, receipts, rollback posture,
and verification. It does not reimplement a plugin's pricing, scheduling,
lookup, caching, or migration algorithms. When the plugin exposes no safe API
or provider capability, support remains explicitly incomplete.

## Adapter authoring loop

Supporting a custom extension follows one product workflow:

1. **Observe** state, relationships, lifecycle activity, and generated effects
   in a controlled environment.
2. **Classify** authored, generated, environment, secret, runtime, and ignored
   surfaces.
3. **Propose** an inert manifest draft with evidence and unresolved questions.
4. **Declare** generic native actions or a provider capability where data is
   insufficient.
5. **Exercise** capture, plan, apply, failure, retry, verification, rollback,
   upgrade, and removal.
6. **Publish** the manifest/provider with explicit compatibility and capability
   evidence.

The authoring tool may generate declarations and provider requirements. It
must not generate PHP regenerators that duplicate plugin behavior by default.

## Product proof and gap harvesting

The flagship proof is a complete ecommerce solution built from WordPress core,
WooCommerce, representative third-party extensions, a custom plugin, and a
custom child theme. It exercises the moves developers actually make:

- initialize or adopt an existing site;
- branch from production;
- install, remove, replace, upgrade, and downgrade extensions;
- author custom code, schema, migrations, lifecycle hooks, cron/queue work,
  settings, and generated data;
- change products, variations, taxonomies, menus, users, media, options, and
  plugin-owned tables;
- preview semantic code-and-state changes;
- refresh/rebase production truth and resolve conflicts;
- promote complete or scoped changes;
- fail, recover, retry, verify, roll back, and restore an exact release.

Every uncovered behavior is harvested into exactly one of:

1. a generic engine primitive;
2. a declarative manifest capability;
3. a structured native action;
4. a plugin-owned provider capability; or
5. an explicit unsupported-capability diagnostic.

The proof must never be made green by adding a plugin-name branch to core,
copying plugin business logic, suppressing a finding, or using an internal
shortcut unavailable to users.

## Active program map

The active Linear program is organized by outcomes rather than the historical
audit sequence:

- **issue #3332 — Functional WordPress proof**
  - issue #3336: 15-minute `wprism init` golden path
  - issue #3337: ecommerce developer move matrix and executable proof
  - issue #3326: code compatibility refusal before activation
- **issue #3333 — Ecosystem-owned adapter boundary**
  - issue #3338: structured native actions and plugin-owned provider contract
  - issue #3339: discovery, trust tiers, and capability catalog
  - issue #3340: custom-extension authoring loop
  - issue #3341: remove WooCommerce-specific policy from core
  - issue #3342: retire the WPrism-owned WooCommerce lookup regenerator
  - issue #3314–issue #3318, issue #3325, and issue #3327: bounded generic primitives
    and authoring support
- **issue #3334 — Daily developer workflows**
  - issue #3343: production refresh/rebase
  - issue #3344: scoped synchronization with dependency closure
  - issue #3345: semantic plan/diff/conflict/explain UX
  - issue #3346: portable environment-driver contract
  - issue #3323 and issue #3324: developer guides and branch materialization
- **issue #3335 — Maintainable engine seams**
  - issue #3347–issue #3355 and issue #3320: behavior-preserving decomposition and
    neutral documentation

Hard dependency gates are intentionally few:

- issue #3338 blocks issue #3314, issue #3317, issue #3325, issue #3327, and issue #3342;
- issue #3315 and issue #3316 block issue #3344; and
- issue #3346 blocks issue #3324.

The product proof, semantic UX, refresh/rebase, and behavior-preserving
refactors may proceed in parallel and should expose gaps early.

## Refactoring posture

Large-file decomposition supports delivery; it is not a rewrite program.

- Characterize public behavior and protocol bytes first.
- Keep compatibility facades while extracting one responsibility at a time.
- Preserve hashes, canonical ordering, errors, command output, lock ordering,
  receipts, crash recovery, deletion authority, and convergence semantics.
- Keep feature redesign out of extraction-only changes.
- Do not let refactoring block the product proof unless a concrete dependency
  is recorded in Linear.

## Verification and refusal posture

Product-first does not mean correctness-optional:

- no silent state loss or raw environment identity in canonical state;
- no guessed destructive deletion;
- no secret or PII capture by inference;
- no provider success without declared effects and value-level verification;
- no hidden collapse of code and state identity;
- no warning-only correctness failure unless separately ratified; and
- no unsupported behavior presented as ecosystem coverage.

Each implemented gap needs regression evidence through the public product path.
Focused local evidence remains required. Broad CI packaging, machine-enforced
engine-purity policy, exhaustive hardening, and wide hosting/version
certification are deliberately outside the current proof program.
