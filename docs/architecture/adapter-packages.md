# Adapter packages and the shipped library

Status: implemented architecture. Source adapters are capsules, adoption
installs an embedded library, and the flat `manifests/` runtime layout is
retired. The migration and reverse-bridge sections remain as cutover
archaeology because they explain the transactional guarantees still enforced.

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
they belong in an explicitly named `integration-scenarios/<name>/` area.

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

`cli/src/Onboarding/Adopt.php` builds this projection through the package
assembler, rejects non-allowlisted members, and tars exactly `agent recovery`.
Recovery continues to ship as its own public runtime and to be staged under
`repo_path/.duo/control/recovery-runtime/`; it is not part of an adapter
package.

The package library is embedded in the agent root for atomicity. Separate
deployed engine, adapter, and platform roots would allow
a request to observe an engine from one release with library bytes from
another. A complete `duo/` root rename instead publishes the engine and its
allowlisted library together. The top-level `duo-loader.php` remains a separate
WordPress-required surface.

## Runtime resolution

The installed agent resolves exactly its embedded `adapter-library/`. There is
no runtime compatibility search and no silent fallback.

Production no longer has a process-global manifest-directory selector, sibling
flat-library search, or `/duo-manifests` fallback. Interpreter, provider,
regenerator, disposition, identity, catalog, certification, and frozen-policy
reads resolve through an explicit `AdapterLibrary`; the installed production
context is the embedded projection.

A missing, malformed, ambiguous, or incomplete embedded library refuses by
name. Tests and host-side tools receive an explicit library context; they
cannot move a production process between libraries through environment state.

## Identity preservation

The layout migration is byte-preserving work, not an opportunity to edit an
adapter. `ArtifactPolicyIdentity::manifest_rows()` binds the decoded manifest,
its reviewed disposition, and the SHA-256 of every declared manifest-owned
interpreter, provider, and regenerator; it does not bind their filesystem paths
(`agent/src/Policy/ArtifactPolicyIdentity.php:75-143`). A relocation is
therefore identity-neutral only when all of those input bytes and the resolved
row ordering are unchanged.

The cutover recorded for every shipped adapter:

- the canonical manifest and disposition bytes;
- every declared executable's bytes and SHA-256;
- the resulting adapter digest;
- representative multi-adapter `manifest_hash` values; and
- the old runtime member to new projection member mapping.

The assembler regressions prove the same values after assembly and prove that
the archive contains no non-allowlisted member. Any adapter whose executable
bytes must change—for example to replace a relative engine import—moves identity
in a separate adapter change, with the normal recompile and re-pin cost. It is
not hidden in the filesystem migration. Manifest JSON, disposition JSON,
canonical output, refusal text, and version defines remain byte-identical unless
a separately reviewed change explicitly owns them.

## Historical forward migration from flat manifests

The implemented cutover extended Adopt's existing identity-bound journal; it
did not add another lock or change recovery lock ordering. The existing
adoption lock is still acquired after read-only topology checks and before
repository, transaction, or staging publication
(`cli/src/Onboarding/Adopt.php:538-552`).

The forward transaction was:

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

## Historical reverse migration and rollback window

At cutover, the prior release's installer could not safely reverse this layout
after the flat directory was removed: that installer published an old agent
before it published `manifests/`. The cutover therefore required a retained,
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

## Durable revocation control

Operator revocations live at
`WPMU_PLUGIN_DIR/duo-control/adapter-revocations.json`, outside the replaceable
agent and its embedded library. Live and frozen verification read that explicit
control document alongside the installed `AdapterLibrary`. A target still
holding the historical flat-library document must first carry a byte-identical
durable copy; adoption refuses before swap when the migration proof is absent.
The embedded platform projection refuses an operator revocation member, and no
runtime directory override or fallback exists.

## Sandbox and archive evidence

The pair estate exports `DUO_AGENT_SRC`, `DUO_ADAPTER_PACKAGES_SRC`, and
`DUO_PLATFORM_SRC`, persists them to Compose configuration, and checks
dirtiness across those three candidate roots. Both web containers mount the
same selected Git worktree, and candidate evidence is bound to cleanliness
across every shipped input.

Archive evidence is structural rather than a restated list. The assembler
exposes a machine-readable member projection that the adapter-kit guard,
release gate, and adoption regressions verify. The invariant remains that the
adapter test kit and every capsule's tests, fixtures, and evidence do not ship.

## Gates

The cutover satisfied the following conditions before package-local iteration
became authoritative:

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

For one adapter, `php tools/adapter-package-validate.php --adapter=<slug>` and
`php tools/adapter-package-tests.php --adapter=<slug>` are the package-local
iteration path. `make regress-offline-all` remains the unconditional global
merge gate: its fixed `regress-adapter-packages` leaf dynamically discovers all
capsules, while the generated corpus covers shared engine/product suites.
Compatibility commands derive `make regress-<suite-name>` from each discovered
`regress_<suite_name>.php` or `.sh` basename, so retaining an old command does
not reintroduce a package path or suite registry in the Makefile.
`make release-gate` remains required. Engine, platform, package-schema,
assembler, shared SDK/harness, release-transaction, unknown, or mixed changes
escalate beyond a single capsule.
