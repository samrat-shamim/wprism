# Your first adapter contribution

An adapter tells WPrism which plugin state is authored, runtime, derived,
environment-bound, or unsupported, and how the admitted state can move safely.
A useful first contribution is often a reproduction or test for an existing
adapter. New support should start with one clearly described user workflow.

## 1. Describe the boundary

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

## 2. Start with a small reproduction

Use synthetic data to reproduce the observed behavior. Put an adapter-owned
offline regression in `adapter-packages/<slug>/tests/offline/`. The test
should exercise the relevant capture, compile, plan, or apply path and assert
that protected runtime state and explicit refusal boundaries survive.

For a new capsule, follow the
[minimal worked example](adapter-authoring.md#the-minimal-worked-example)
and [directory conventions](adapter-authoring.md#directory-conventions).
The long authoring guide is a reference for the state shapes you encounter,
not a prerequisite to memorize before submitting a reproduction.

## 3. Validate locally

Replace `<slug>` with the capsule's directory name:

```sh
php tools/adapter-package-validate.php --adapter=<slug>
php tools/adapter-package-tests.php --adapter=<slug>
```

The validator checks structure, identity, dispositions, and evidence wiring.
It does not turn an unreviewed proposal into a certified capability.
Package-owned tests are automatically included in the global offline gate;
do not add individual adapter tests to the Makefile.

## 4. Exercise native behavior

After the offline mechanism is pinned, use a disposable WordPress pair to
exercise the smallest reasonably safe native boundary. Include native
authoring, divergent local identities where relevant, target runtime
preservation, repeat application, and a truthful unsupported case.
[The sandbox guide](../sandbox.md) explains pair ownership and cleanup.

Keep adapter-specific fixtures, tests, and evidence inside the capsule.
Cross-adapter behavior belongs in a named participant-declared integration
scenario.

## 5. Submit the claim for review

Follow [CONTRIBUTING.md](../../CONTRIBUTING.md) and its full local gates.
Describe exactly which operation becomes possible and which boundaries remain.
Maintainers review disposition changes; the authoring tool cannot self-certify
the adapter.

Any change to declared identity-bearing package files moves that adapter's
digest. Explain the resulting recompile/re-pin requirement, avoid incidental
formatting, and preserve unrelated package bytes. A contribution that narrows
an overbroad claim can be as useful as one that adds support.

References: [adapter authoring](adapter-authoring.md),
[known grammar limitations](adapter-authoring-limitations.md),
[capability semantics](../capabilities.md).
