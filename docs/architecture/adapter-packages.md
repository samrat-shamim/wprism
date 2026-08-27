# Adapter packages and the shipped library

Status: proposed migration architecture. The repository and installed runtime
still use the flat `manifests/` library described below; this document defines
the required end state and the conditions for reaching it.

## Decision

An adapter is authored as one source capsule:

```text
adapter-packages/
  <slug>/
    package/
      manifest.json
      disposition.json
      runtime/
        interpreters/
        providers/
        regenerators/
    tests/
      offline/
      live/
      certify/
      conformance/
    fixtures/
    evidence/
    README.md

platform/
  adapter-library/
    core/
    profiles.json
    capabilities/
      platform.json
      adapter-authorities.json
```

`package/` is the only shipped part of an adapter capsule. Tests, fixtures,
evidence, and authoring documentation remain beside it so that ordinary work on
one adapter changes only `adapter-packages/<slug>/`, but none of those files
reach a managed site. Cross-adapter scenarios are not owned by either capsule;
they belong in an explicitly named integration-scenario area.

`platform/` owns the WordPress core policy, the reviewed profile vocabulary,
and agent-wide capability and authority configuration. Engine changes may
change `agent/`, `cli/`, `recovery/`, or `platform/`; they must not rewrite
adapter capsules as a side effect. Adapter runtime may depend only on the
versioned adapter SDK exposed by the engine. Engine code must not import an
adapter package or branch on an adapter slug.

## Deployment projection

Adoption assembles an allowlisted projection in scratch, outside the source
checkout, and installs it at:

```text
agent/
  adapter-library/
    platform/
    adapters/
      <slug>/
        manifest.json
        disposition.json
        runtime/
```

Here `agent/` denotes the staged and installed agent root
`WPMU_PLUGIN_DIR/duo/`; `agent/adapter-library/` is not an authoring source and
must not be manufactured as scratch under the checkout's real `agent/`.
Assembly copies only files admitted by the package and platform schemas. An
undeclared PHP file, symlink, special node, path escape, test, fixture, evidence
file, or authoring document is a build refusal, not an extra archive member.

This projection replaces the current direct archive construction in
`cli/src/Onboarding/Adopt.php:207-213`, which tars `agent manifests recovery`,
and the current target copy from `stage/manifests` at `:560-571`. Recovery
continues to ship as its own public runtime and to be staged under
`repo_path/.duo/control/recovery-runtime/`; it is not part of an adapter
package.

The package library is embedded in the agent root for atomicity. Today Adopt
journals agent, loader, flat manifests, and `.duo` as four separate surfaces
(`cli/src/Onboarding/Adopt.php:378-409`) and publishes them sequentially
(`:586-590`). Separate deployed engine, adapter, and platform roots would allow
a request to observe an engine from one release with library bytes from
another. A complete `duo/` root rename instead publishes the engine and its
allowlisted library together. The top-level `duo-loader.php` remains a separate
WordPress-required surface.

## Runtime resolution

The installed agent resolves exactly its embedded `adapter-library/`. There is
no runtime compatibility search and no silent fallback.

The migration removes all three current branches of
`Policy::manifests_dir()`—`DUO_MANIFESTS_DIR`, the sibling `manifests/`, and
`/duo-manifests` (`agent/src/Policy/Policy.php:278-288`)—and the duplicate
partial-load fallback in `ManifestDispositions`
(`agent/src/Policy/ManifestDispositions.php:517-530`). Interpreter, provider,
regenerator, disposition, identity, catalog, certification, and frozen-policy
reads must resolve through one package-library interface rather than append
paths to a manifest directory.

A missing, malformed, ambiguous, or incomplete embedded library refuses by
name. Tests and host-side tools receive an explicit library context; they do
not move a production process between libraries by changing
`DUO_MANIFESTS_DIR`. In particular, the two environment mutations in
`cli/src/Refresh/RefreshPlan.php:144-183` and `:302-307` must become explicit
library inputs before the legacy environment contract can be removed.

## Identity preservation

The layout migration is byte-preserving work, not an opportunity to edit an
adapter. `ArtifactPolicyIdentity::manifest_rows()` binds the decoded manifest,
its reviewed disposition, and the SHA-256 of every declared manifest-owned
interpreter, provider, and regenerator; it does not bind their filesystem paths
(`agent/src/Policy/ArtifactPolicyIdentity.php:75-143`). A relocation is
therefore identity-neutral only when all of those input bytes and the resolved
row ordering are unchanged.

Before moving sources, record for every shipped adapter:

- the canonical manifest and disposition bytes;
- every declared executable's bytes and SHA-256;
- the resulting adapter digest;
- representative multi-adapter `manifest_hash` values; and
- the old runtime member to new projection member mapping.

The cutover must prove the same values after assembly and must prove that the
archive contains no non-allowlisted member. Any adapter whose executable bytes
must change—for example to replace a relative engine import—moves identity in a
separate adapter change, with the normal recompile and re-pin cost. It is not
hidden in the filesystem migration. Manifest JSON, disposition JSON, canonical
output, refusal text, and version defines remain byte-identical unless a
separately reviewed change explicitly owns them.

## Forward migration from flat manifests

The migration extends Adopt's existing identity-bound journal; it does not add
another lock or change recovery lock ordering. The existing adoption lock is
still acquired after read-only topology checks and before repository,
transaction, or staging publication (`cli/src/Onboarding/Adopt.php:538-552`).

The forward transaction is:

1. Assemble, validate, archive, upload, and inspect a complete generation-marked
   artifact without changing a target.
2. Refuse an unknown target generation, unsafe legacy path, active adoption,
   unresolved legacy environment override, or unresolved revocation document.
3. Construct `agent_new` completely, including `adapter-library/`, before
   recording its final publish-source identity.
4. Record the old agent, loader, `.duo` authority, and legacy flat-manifest
   identity or absence.
5. Publish the new agent root, then the loader. The new agent can immediately
   read the matching embedded library; until the agent move, the old agent can
   still read its flat library.
6. Retire `WPMU_PLUGIN_DIR/manifests` by moving it to the transaction's owned
   backup. This is a journaled retirement whose required new live state is
   absence, not a replacement flat directory.
7. Publish the staged `.duo` authority and its recovery runtime.
8. In fresh processes, verify the exact agent version, exact embedded library,
   policy load, recovery authority, and transactional doctor. Also prove that
   the legacy path is not selected.
9. Publish the existing mutation-free commit barrier. Only after the host has
   observed it may cleanup delete old roots and the retired flat library.

Before that barrier, rollback restores `.duo`, restores the flat library,
restores the loader, and restores the old agent. The critical dependency is
that the flat library is live before the old agent becomes live. If a move or
its immutable post-move proof is incomplete, retain the lock and journal for
operator recovery, as Adopt does today; do not infer a rollback from partial
state. A fresh installation records the legacy library as absent and never
creates it.

## Reverse migration and rollback window

The prior release's installer cannot safely reverse this layout after the flat
directory has been removed: its current order publishes an old agent before it
publishes `manifests/`. The cutover therefore requires a retained,
generation-aware bridge installer and a retained prior artifact. This is part
of the release, not an optional operator convenience.

For package layout to legacy layout, the bridge transaction:

1. Stages and validates the complete legacy artifact under the same adoption
   lock and journal.
2. Publishes the flat legacy library while the package-layout agent is still
   live and ignores it.
3. Publishes the legacy agent, then loader, then `.duo` authority.
4. Verifies the legacy agent resolves that exact flat library before commit.
5. Cleans the old package-layout agent only after the commit barrier.

Rollback of that reverse attempt restores `.duo` and loader, restores the
package-layout agent, and only then removes the newly published flat library.
Thus each live agent always has its own library generation. Running the old
checkout's unmodified `duo adopt` is not the reverse-migration procedure.
Retirement of the bridge and prior artifact requires an explicit dated fleet
decision after the rollback window closes.

## Revocation blocker

`capabilities/adapter-revocations.json` is currently an operator-installed,
self-authenticating document under the manifest directory. The code records
that re-adoption can overwrite it
(`agent/src/Adapter/AdapterCertification.php:476-495`), and
`docs/guides/trust-enrollment.md:357-365` records the resulting silent-erasure
residual.

The flat directory cannot be retired until this document has a durable home
that live and frozen verification both read, or migration refuses before any
swap and gives an explicit relocation procedure. It must not be silently
discarded, copied into an unauthenticated site source, or treated as an adapter
package file. The same preflight must refuse a target that deliberately selects
an external `DUO_MANIFESTS_DIR`; ignoring an operator's selected library is not
a migration.

## Sandbox and archive evidence

The pair estate currently exports `DUO_AGENT_SRC` and `DUO_MANIFESTS_SRC`
(`sandbox/lib/pair_identity.sh:39-47`), persists them to Compose configuration
(`sandbox/lib/pair_compose.sh:62-83`), mounts `/duo-manifests`
(`sandbox/pair.yml:93-125`), and checks dirtiness only under `agent manifests`
(`sandbox/bin/pair.sh:325-374`). The package cutover must replace that contract
with sources for `agent/`, `adapter-packages/`, and `platform/`, verify that all
baked mounts in both web containers come from the same selected Git worktree,
and bind candidate evidence to cleanliness across every shipped input.

Archive evidence must be structural rather than a restated list. The existing
adapter-kit guard reads the literal tar composition from Adopt
(`tools/adapter-kit.php:140-170`); the assembler must instead expose a
machine-readable member projection that the kit guard, release gate, and
adoption regressions all verify. The invariant remains that the adapter test
kit and every capsule's tests, fixtures, and evidence do not ship.

## Gate transition

The architecture cutover itself runs the full current local gate. Scoped
adapter testing becomes authoritative only after all of the following are
implemented and self-tested:

1. Capsule and platform discovery are complete and fail closed: no package or
   recognized test file can exist without being selected by a gate.
2. The package validator enforces the allowlist, package-name agreement,
   declared executable closure, path safety, and cross-package isolation.
3. Engine-to-adapter and adapter-to-engine dependency rules are mechanically
   enforced, with adapter runtime limited to the public SDK.
4. Cross-adapter behavior lives in named integration scenarios whose declared
   participants select them automatically.
5. A changed-path classifier has a closed ownership vocabulary. An adapter-only
   change selects that adapter and its integration scenarios; engine,
   `platform/`, schema, assembler, shared harness, unknown, or mixed changes
   escalate to the full gate.
6. Identity-neutral assembly, archive allowlisting, fresh install, flat-to-
   package update, package-to-package update, reverse migration, failure
   rollback, committed cleanup, pair provenance, and live SSH adoption all have
   product-path regressions.
7. Global adapter edit points—hard-coded adapter lists, Makefile leaf wiring,
   generated corpus counts, readiness rows, and capability tables—have been
   replaced by checked discovery or package-local sources.

Until those conditions are green and the repository instructions are changed
explicitly, `make regress-offline-all` remains the unconditional merge gate and
`make release-gate` remains required. A package-only fast path is iteration
evidence, not merge authority. Full-project evidence remains mandatory for the
cutover and for every later change to the engine, platform, package schema,
assembler, shared SDK or harness, release transaction, or unknown ownership
boundary.
