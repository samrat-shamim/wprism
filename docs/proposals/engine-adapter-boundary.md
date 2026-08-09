# Engine core, adapter packages, and the WordPress SDLC — boundary doctrine

*Owner ruling — 2026-08-09, refreshed for the product-first program tracked in
Linear's [Engine/Adapter Boundary & WordPress SDLC](https://linear.app/duotronic/project/engineadapter-boundary-and-wordpress-sdlc-c0fdf8775799)
project. The project description and its “Fresh product and architecture
roadmap” are the companion planning authority. Historical issue sequencing is
not authoritative.*

This document defines the durable boundary. It intentionally avoids transient
line numbers, branch names, audit counts, and pull-request sequencing. If an
issue proposes a different boundary, stop and obtain an owner ruling rather
than silently weakening this doctrine.

## Product outcome

Duo should be the ordinary development and operations workflow for WordPress:
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

### 4. Plugin-owned provider

A provider supplies executable semantics the plugin already owns. It is loaded
from the plugin itself or from an independently distributed, versioned adapter
package—not copied into Duo core.

The provider contract must define:

- deterministic identity and compatible plugin/provider versions;
- capability negotiation before mutation;
- structured, bounded inputs and outputs;
- declared reads, writes, external effects, and required lifecycle context;
- batching, heartbeat, timeout, idempotency, retry, and failure semantics;
- receipts sufficient for recovery; and
- value-level verification, not command-success-only verification.

Duo's provider SDK may offer generic facilities such as checked database reads,
canonical codecs, scoped context, heartbeat, receipts, and error mapping. It
must not become a second home for plugin APIs or business rules.

### 5. Compatibility shim

An executable Duo-owned shim is an exceptional, temporary bridge for an
ecosystem limitation. It must be quarantined outside engine decision logic,
named in capability diagnostics, version-bounded, and assigned a removal path.
It is never the default adapter-authoring model and never masquerades as a
declarative manifest or plugin-owned provider.

## Trust and capability are distinct

The trust decision depends on what is installed:

- a data-only manifest receives declarative authority only;
- a native action invokes reviewed generic engine behavior;
- a provider is executable code trusted as part of the installed plugin or
  adapter package; and
- a compatibility shim is executable and explicitly reported as exceptional.

Certification is evidence about supported behavior, not a substitute for the
trust decision. Certified, exercised, uncertified, incompatible, missing, and
ambiguous capabilities must remain visibly different in `status`, `plan`, and
adapter diagnostics.

Those six words are this doctrine's, and the shipped vocabulary is smaller by
design — a status word minted to match prose would read as reviewed evidence
next to `dispositions.json`, which is precisely the substitution the
certification separation exists to refuse (`agent/src/AdapterSources.php`
states the same rationale for the fourth word `uncertified`). So the mapping is
stated here instead of being invented in code:

| Doctrine word | Where it is visibly reported today |
|---|---|
| certified | `dispositions.json` status `certified` + a `certified` verdict from `CapabilityRegistry::report()`; `duo adapter list` disposition column |
| exercised | not a status. The evidence facts behind it are: `evidence.status` (`current`/`candidate`), `plugin_execution.status` (`verified`/`unverified`/`not-a-product-claim`), and each named test citation resolved against the bundle's own verdict — all three printed by `duo adapter inspect` |
| uncertified | the fourth status word, carried by an out-of-tree adapter's synthesized provenance record; blocker code `adapter_source_uncertified` |
| incompatible | two separate code sets, deliberately not merged: a **certification** verdict from `CapabilityRegistry::report()` whose reason names the mismatch (`plugin_version_mismatch`, `wordpress_version_mismatch`, `php_version_mismatch`, `database_version_mismatch`, `theme_version_mismatch`, `revision_not_certified`, `multisite_unsupported`), and a **negotiation** problem row from `Providers::diagnose()` (`outside_version_range`, `identity_mismatch`, `contract_shape`, `malformed_capability`, `invalid_capability_args`, `non_idempotent_capability`) |
| missing | certification: `missing_registry_entry`. Negotiation: `missing_plugin`, `inactive_plugin`, `missing_plugin_provider`, `missing_capability`, `undeclared_provider`, `provider_code_unavailable` |
| ambiguous | refused rather than reported as a status — `AdapterSources` refuses ambiguous identity, shadowing, declared-name collisions, case-fold confusables, an unanchored plugin bundle, and two active plugins bundling one name at load, and `duo adapter doctor` / `wp duo adapter-survey` report the same conditions as refusal rows with codes `ambiguous_identity`, `shadows_shipped`, `name_collision`, `case_collision`, `plugin_anchor_mismatch`, `source_collision`. One ambiguity is deliberately NOT refused: a plugin-bundled name a shipped or site definition already answers to is RESOLVED by the source precedence `shipped > site > plugin` and reported as an installed-but-not-loaded row naming its winner. Ambiguity is refused where the operator authored both sides; where a third party's update created it, resolving it loudly beats taking the site down (see spec/repo-format.md, "Plugin-bundled adapters") |

The requirement the table serves is unchanged: each of those states must be
distinguishable wherever capabilities are reported. It is the *word* that is
not minted, not the distinction.

An unsupported or unverifiable capability fails before destructive writes.
Duo reports the missing capability, responsible adapter/provider, compatible
versions, and remediation path rather than guessing or silently degrading.

## Generated state belongs to the plugin's semantics

Duo distinguishes authored truth from generated tables, caches, indexes, and
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

- **DUO-3332 — Functional WordPress proof**
  - DUO-3336: 15-minute `duo init` golden path
  - DUO-3337: ecommerce developer move matrix and executable proof
  - DUO-3326: code compatibility refusal before activation
- **DUO-3333 — Ecosystem-owned adapter boundary**
  - DUO-3338: structured native actions and plugin-owned provider contract
  - DUO-3339: discovery, trust tiers, and capability catalog
  - DUO-3340: custom-extension authoring loop
  - DUO-3341: remove WooCommerce-specific policy from core
  - DUO-3342: retire the Duo-owned WooCommerce lookup regenerator
  - DUO-3314–DUO-3318, DUO-3325, and DUO-3327: bounded generic primitives
    and authoring support
- **DUO-3334 — Daily developer workflows**
  - DUO-3343: production refresh/rebase
  - DUO-3344: scoped synchronization with dependency closure
  - DUO-3345: semantic plan/diff/conflict/explain UX
  - DUO-3346: portable environment-driver contract
  - DUO-3323 and DUO-3324: developer guides and branch materialization
- **DUO-3335 — Maintainable engine seams**
  - DUO-3347–DUO-3355 and DUO-3320: behavior-preserving decomposition and
    neutral documentation

Hard dependency gates are intentionally few:

- DUO-3338 blocks DUO-3314, DUO-3317, DUO-3325, DUO-3327, and DUO-3342;
- DUO-3315 and DUO-3316 block DUO-3344; and
- DUO-3346 blocks DUO-3324.

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
