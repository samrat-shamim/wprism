# Adapter library and manifest ownership

Start with [the adapter authoring guide](../guides/adapter-authoring.md). This page is the detailed reference for this part of the workflow.

## What a manifest is, and what it is not

A manifest is `adapter-packages/<name>/package/manifest.json`, inside the
adapter's source capsule, and is pinned by name from a site's
[`site.wprism.json`](../../spec/repo-format.md#sitewprismjson). It declares
classification rules, reference shapes, deletion capability, derived-state
repair actions, and a compatibility window.

It is **not** a certification. A manifest cannot certify itself merely by
existing beside the agent — that separation is deliberate, and the agent says
so in the exact words it refuses with: `no reviewed disposition entry — a
manifest cannot certify itself merely by existing beside the agent`. See
[Dispositions](adapter-claims.md#dispositions-the-reviewed-claim-source) below.
## Directory conventions

```
adapter-packages/<name>/
  package/
    manifest.json                    # declared adapter policy
    disposition.json                 # reviewed support boundary; NOT a manifest
    runtime/
      interpreters/<name>.php        # \WPrism\Interpreters\<Name>
      regenerators/<name>.php        # \WPrism\Regenerators\<Name>
      providers/<id>.php             # \WPrism\Providers\<Id>
  tests/                             # offline/live/certify/conformance evidence
    offline/regress_<name>_*.php
    conformance/{entry.json,seed.sh,check.sh}
    certify/version-matrix.sh
  fixtures/                          # capsule-owned historical/probe inputs
    <workflow>/                     # native PHP, shell helpers and host readers
  evidence/
    artifacts.lock.json              # exact official artifact URLs + sha256
    production-readiness.json        # all 12 hostile scenario families
    target-observation-premises.tsv  # ratchet for live observations/fixtures
    external-tests.json              # optional integration-scenario citations

platform/adapter-library/
  core/{manifest,disposition}.json
  profiles.json
  capabilities/platform.json         # the reviewed platform boundary
  capabilities/adapter-authorities.json # platform trust root; ships {"keys":{}}
```

Only `package/` is assembled into the installed agent. Tests, fixtures, and
evidence stay in the capsule but do not ship. Both platform capability files
are hand-authored and reviewed, not generated.
Every product capsule also owns `tests/conformance/entry.json`, `seed.sh`, and
`check.sh`; the global `regress-conformance-asserts` gate enforces their presence
even when the focused package validator passes. A source-only experimental
entry uses `mode: "capture-plan"`, puts its source assertions in
`capture-check.sh`, and makes `check.sh` explicitly refuse accidental target
promotion. A bounded native Apply suite can qualify individual settings while
the broader deployment entry remains source-only.

Once the experimental disposition declares both `deploy` and `apply`, use
`mode: "agent-roundtrip"` to exercise target activation, Apply, plugin consumers
and byte-identical recapture. The harness first proves that host deployment
still refuses the experimental claim, then runs the public agent verbs. This
mode cannot emit a certified conformance vector and does not grant production
promotion. Certification also requires the owned version matrix and ready
evidence across all twelve scenario families; see the package validator.

If a schema or lifecycle provider makes standalone target deploy correctly
refuse for lack of the host checkpoint/session ordering, do not keep a hollow
experimental `deploy` claim just to reuse that profile. Use
`mode: "agent-apply-roundtrip"`: it requires experimental
capture/compile/plan/apply/recapture claims with deploy absent, proves both the
host certification refusal and the direct provider-boundary refusal, then lets
the disposable harness establish exact native plugin/theme lifecycle state.
Package hooks still exercise the real provider, Apply, native consumers and
recapture paths. Deployment becomes claimable only when certified host evidence
can exercise it; fixture lifecycle setup is explicitly not deploy evidence.

An entry may declare `adopt_by_slug` as a unique array drawn from `terms`,
`posts`, `menus`, and `tables`. The default is `["terms", "posts"]`; an empty
array explicitly selects no adoption. This is fixture intent for an existing
target, validated before pair mutation, not a shipped adapter permission.
For example, a template roundtrip that seeds existing target rows declares
`["terms", "posts", "tables"]`. Keep target-local preservation assertions in
the capsule's post-Apply check. Reusable shell helpers belong under `fixtures/`;
premise contracts may name active statements there as well as under `tests/`.
Both roots are scanned for required evidence contracts.

`platform.json` is the object a site-adapter certificate reads its bound
compatibility cells out of (§ v3.6), so it has exactly one on-disk
representation; the agent refuses at load time if its
`agent_version`/`spec_version` disagree with the running
`WPRISM_AGENT_VERSION`/`WPRISM_SPEC_VERSION`.

A capsule's `tests/live/*.sh` starts with these exact three active statements;
`regress-fetch-artifact` checks their order so artifact resolution always has
one capsule owner before any wrapper or conditional code runs:

```bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
```

Keep this preamble in each live entry point when extracting setup into a
sourced fixture. The fixture may validate the caller's capsule authority, but
must not replace it: `regress_fetch_artifact.sh` checks the caller's first three
statements independently of the fixture's execution. Put path normalization and
other setup after this preamble. Create the ignored
`sandbox/tmp` parent before allocating evidence below it; a fresh worktree does
not contain that directory. Source shared helpers through the reviewed paths
and capsule hooks through their `BASH_SOURCE`-relative paths, as enforced by
`AdapterPackageValidator`.

Four rules that will bite you if you learn them the hard way:

- **The capsule name is the pin name, and it is enforced.** `Policy::load()`
  resolves a pin through the installed `AdapterLibrary`, while package
  discovery, the reviewed disposition, and `AdapterRegistry` all key the
  loaded library by the manifest's own `"name"` field. A disagreement would be one adapter
  under two identities, so it is refused the moment the manifest is read — by
  name, with both values:

  ```
  wprism: shipped adapter '<path>' declares name 'y' but its file name is 'x' — a pin
  names the file while every downstream identity (dispositions, digests,
  diagnostics) keys off the declared name, so the two disagreeing is ambiguous
  identity. Make the declared name match the file name
  ```

  The same sentence refuses a site-installed adapter (see below), and
  `wprism manifest-validate <dir>` reports it per manifest offline, before any
  target is contacted.
- **`package/disposition.json` is not part of the manifest.** It is reviewed
  data *about* that one declaration. Package validation requires exactly one
  manifest/disposition pair; profiles remain platform-owned at
  `platform/adapter-library/profiles.json`.
- **A regression fixture is marked by its disposition, not by its name.** Give
  it `"status": "excluded"` and the generated document prints it as shipping
  "for regression use only" and carrying no product claim; the projected claim
  reports `authored_state.status: unsupported` and
  `plugin_execution.status: not-a-product-claim`. `wprism-agency-cpt` is the one
  shipped example. A name prefix decides nothing — that rule is gone with the
  generator that read it.
- **Interpreter, regenerator, and provider code ships with the manifest, not
  the engine.** A declared interpreter name resolves to
  `package/runtime/interpreters/<name>.php` inside the same capsule and must define
  `\WPrism\Interpreters\<CamelCase(name)>` with
  `post_meta_rule(string $key, array $allMeta): ?array`; it may additionally
  define `term_meta_rule()` and `user_meta_rule()` with the same signature and
  nullable-defer semantics. A regenerator, declared under a post type's
  `regen_dependency`, resolves to `package/runtime/regenerators/<name>.php` and must
  define `\WPrism\Regenerators\<CamelCase(name)>` with
  `regenerate(int $localId): void`. A manifest-sourced provider resolves to
  `package/runtime/providers/<id>.php` and must define
  `\WPrism\Providers\<CamelCase(id)>` with `identity()`, `capabilities()`, and
  `invoke()`. A missing file is a loud load-time error naming the exact path.

That last one is worth stating without euphemism: **WPrism loads PHP shipped from
an adapter capsule's `package/runtime/`.** Adoption embeds that allowlisted
package projection in `agent/adapter-library/`, so loading it is the same trust
decision as running the installed agent. Content digests strengthen that: all three files —
interpreter, manifest-sourced provider, and (since issue #3360) regenerator — join
the per-adapter content digest, so editing any of them is a *changed adapter*
rather than invisible drift behind a stable manifest digest. Two
implementations can no longer share one manifest revision's identity.

<a id="editing-a-shipped-manifest-moves-its-identity"></a>

> **Editing a shipped manifest's bytes, or a hook file it names, moves that
> adapter's identity — and deployed sites refuse until you re-pin.**
> `ArtifactPolicyIdentity::manifest_rows()` builds one row per manifest
> carrying the manifest array, its disposition, and `hash_file('sha256', …)`
> of each named interpreter, provider and regenerator file.
> `manifest_hash()` is sha256 over the canonical encoding of all those rows;
> `resolved_adapters()` hashes each row *individually* into that adapter's
> `digest`. It is the **same row** folded both ways, so a site repo's
> per-manifest content pin, `adapter_digest`, the digest `wprism assess` reports,
> and the contract that pins it are all this one row hashed. Change a byte and
> a deployed site with a compiled artifact refuses with
> `compiled_artifact_manifest_mismatch` — *compiled manifest/interpreter set
> does not match active pins* — and the `site.wprism.json` content pin stops
> matching too.
>
> The remedy is recompile and re-pin: rebuild the artifact and update the
> reviewed pin, which `wp wprism manifest-pin --repo=<site-repo> --name=<name>`
> emits as a copy-pasteable object. Updating a pin is an explicit review act
> and is never automatic.
>
> What does **not** move identity: renaming a PHP namespace, or moving an
> `agent/src` class file. `manifest_rows()` folds manifest JSON bytes,
> disposition bytes, and the sha256 of the named hook files — nothing else.

After an intentional shipped-byte edit, measure the changed identities before
updating the current literal baselines in `regress_disposition_split.php`,
`regress_spec_v3_digest_neutrality.php` and their
`sandbox/tests/fixtures/spec-v3/wprism-greenfield-identity.json` fixture.
Re-pin only measured changed addresses, including the fixture's own byte hash;
leave historical transitions and frozen executable-debt hashes untouched.
A provider-only edit can move its adapter and compatible manifest hashes while
leaving manifest JSON, the disposition registry and policy snapshots unchanged.
Run `make regress-disposition-split regress-spec-v3-digest-neutrality` before
the aggregate: capsule validation does not replace those cross-library
identity checks. Preserve prior transition literals; when reviewed disposition
fields change, reconstruct the historical claim before checking its old hash.

When a reviewed plugin version range changes, also run `make
regress-manifest-dispositions`. Its literal ranges must agree with the capsule's
manifest and disposition after the corresponding native evidence is accepted.
Refreshing identity hashes alone does not check those version expectations.

A new capsule also extends the source census: run `make
regress-spec-v3-digest-neutrality regress-spec-v3-document
regress-spec-v3-dry-run regress-spec-window` before the full gate. The literal
identity fixture now preserves its historical 21-subject cohort. Do not extend
or re-pin that historical fixture merely because a capsule joins the library:
`regress_spec_v3_digest_neutrality.php` separately loads every later capsule
with core and round-trips its frozen policy against the complete current
registry. A conflict in that core-plus-capsule load needs a reviewed compatible
test world, not removal of the census check. Existing capsule digests and
historical pin sets remain unchanged unless their identity inputs changed.
Regenerate `tools/shipped-identity-inventory.php`, `tools/api-surface.php
--write`, and `tools/wire-surface.php generate` for the actual library census.
The current whole-registry address and frozen snapshots still move when an
unpinned disposition is added; § v3.4's WP-4.5 addressing proposal is explicitly
unimplemented. Preserving the historical fixture does not close this
architectural limitation.
## Precedence, in one sentence each

- **Site policy always wins.** A rule in `site.wprism.json`'s `policy` outranks
  every manifest, for every section.
- **A non-core manifest outranks `core`.** The loader keeps scanning past a
  `core` match specifically so a plugin's own declaration takes it — a
  reclassification of a core option by a plugin manifest is legal and loud.
- **Patterns are the last resort.** `option_patterns`, `post_meta_patterns`,
  `meta_patterns`, and their kin are consulted only after every exact rule has
  missed. Prefer `post_meta_patterns` for a family proved only in
  `wp_postmeta`; legacy `meta_patterns` deliberately reaches term metadata too.
- **Anything still unmatched is unclassified**, which is a loud abort, not a
  default.
