# Author an adapter

An adapter tells WPrism which plugin state is authored, runtime, derived,
environment-bound, or unsupported, and how the admitted state can move safely.
A useful first contribution is often a reproduction or test for an existing
adapter. New support should start with one clearly described user workflow.

## Make your first contribution

### 1. Describe the boundary

Open a proposal naming the plugin, exact versions, native authoring action,
stored fields, expected result, and protected state that must stay local.
Explain references to local IDs, derived data, lifecycle effects, and what
remains unsupported. Avoid using a plugin's name as a whole-plugin claim.

Inspect the current library with:

```sh
cli/wprism adapter list
cli/wprism help adapter
```

Read the relevant capsule in `adapter-packages/<slug>/`. Its
`package/manifest.json` declares behavior; `package/disposition.json` records
reviewed scope; sibling tests and evidence exercise that scope.

### 2. Start with a small reproduction

Use synthetic data to reproduce the observed behavior. Put an adapter-owned
offline regression in `adapter-packages/<slug>/tests/offline/`. The test
should exercise the relevant capture, compile, plan, or apply path and assert
that protected runtime state and explicit refusal boundaries survive.

For a new capsule, follow the
[minimal worked example](../reference/adapter-fields.md#the-minimal-worked-example)
and [directory conventions](../reference/adapter-library.md#directory-conventions).
The references below cover the state shapes you encounter; choose the chapter
that matches the boundary you are working on.

### 3. Validate locally

Replace `<slug>` with the capsule's directory name:

```sh
php tools/adapter-package-validate.php --adapter=<slug>
php tools/adapter-package-tests.php --adapter=<slug>
```

The validator checks structure, identity, dispositions, and evidence wiring.
It does not turn an unreviewed proposal into a certified capability.
Package-owned tests are automatically included in the global offline gate;
do not add individual adapter tests to the Makefile.

### 4. Exercise native behavior

After the offline mechanism is pinned, use a disposable WordPress pair to
exercise the smallest reasonably safe native boundary. Include native
authoring, divergent local identities where relevant, target runtime
preservation, repeat application, and a truthful unsupported case.
[The sandbox guide](../sandbox.md) explains pair ownership and cleanup.

Keep adapter-specific fixtures, tests, and evidence inside the capsule.
Cross-adapter behavior belongs in a named participant-declared integration
scenario.

### 5. Submit the claim for review

Follow [CONTRIBUTING.md](../../CONTRIBUTING.md) and its full local gates.
Describe exactly which operation becomes possible and which boundaries remain.
Maintainers review disposition changes; the authoring tool cannot self-certify
the adapter.

Any change to declared identity-bearing package files moves that adapter's
digest. Explain the resulting recompile/re-pin requirement, avoid incidental
formatting, and preserve unrelated package bytes. A contribution that narrows
an overbroad claim can be as useful as one that adds support.
## Authoring for an existing site

Use a disposable site with the exact official plugin artifact. Inspect its
native settings and public save paths before drafting policy. `adapter-observe`
collects a bounded inventory; `adapter-draft` records proposed rules and open
questions. A draft does not grant a capability or execution authority.

```sh
cli/wprism help adapter-observe
cli/wprism help adapter-draft
cli/wprism help adapter
```

The [detailed authoring workflow](../reference/adapter-workflow.md) orders
observation, representative native writes, proposals, drafts, schema probes,
version-boundary checks, certification, pinning, and repeat application. Keep
unknown or unsupported surfaces outside the managed disposition. Provision
required environment values before treating a completed baseline as ready.

## Adversarial preflight

Prove the native consumer contract, not just a getter round trip. Make
custom-table ids deliberately differ between source and target; inspect
PHP-serialized containers and encoded references; retain foreign rows and
runtime fields. Verify capture, offline compilation, public Plan/Apply, repeat,
recapture, and the relevant refusal and recovery boundaries. The
[preflight reference](../reference/adapter-validation.md#adversarial-preflight)
contains the complete checklist and harness prerequisites.

## Reference chapters

| Question | Reference |
|---|---|
| Where do files belong, and what ships? | [Library ownership and precedence](../reference/adapter-library.md) |
| How do I declare a stored field or local reference? | [Field and reference recipes](../reference/adapter-fields.md) |
| How do repair, lifecycle, and schema work fit together? | [Actions and providers](../reference/adapter-actions.md) |
| How do I validate grammar and exercise hostile cases? | [Validation and preflight](../reference/adapter-validation.md) |
| What is the complete installed-site authoring sequence? | [Detailed workflow](../reference/adapter-workflow.md) |
| What supports a reviewed capability claim? | [Dispositions](../reference/adapter-claims.md) and [capability semantics](../capabilities.md) |
| How do site adapters, certificates, and overrides work? | [Certification and trust](../reference/adapter-trust.md) |
| Which extension channels and limits apply? | [Constraints](../reference/adapter-constraints.md) and [generated limitation ledger](adapter-authoring-limitations.md) |

The [repository specification](../../spec/repo-format.md#adapter-manifests-package-format)
is the normative grammar. Commands and flags come from built-in help.
