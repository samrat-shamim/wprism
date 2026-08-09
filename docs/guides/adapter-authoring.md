# Authoring an adapter manifest

A manifest is how Duo learns what one plugin's state *means*: which keys are
portable authored intent, which are environment-local noise, which hold entity
references that must be rewritten across environments, and which tables it may
touch at all. The engine holds no plugin names and no plugin logic — every
plugin-specific fact lives in a manifest.

This guide is the authoring loop. The normative format is
[spec/repo-format.md § Manifests (registry format)](../../spec/repo-format.md#manifests-registry-format),
and everything a manifest may declare is enumerated there. Read it alongside
this page rather than instead of it.

## What a manifest is, and what it is not

A manifest is a JSON file in the platform repository's `manifests/` directory,
pinned by name from a site's
[`site.duo.json`](../../spec/repo-format.md#siteduojson). It declares
classification rules, reference shapes, deletion capability, rebuild commands,
and a compatibility window.

It is **not** a certification. A manifest cannot certify itself merely by
existing beside the agent — that separation is deliberate and is enforced in
code. See [Dispositions](#dispositions-and-the-capability-registry) below.

## Directory conventions

```
manifests/
  <name>.json                  # the manifest; basename is the pin name
  dispositions.json            # the reviewed support boundary; NOT a manifest
  interpreters/<name>.php      # \Duo\Interpreters\<Name>
  regenerators/<name>.php      # \Duo\Regenerators\<Name>
  capabilities/registry.json   # GENERATED; never hand-edited
```

Four rules that will bite you if you learn them the hard way:

- **The file basename is the pin name.** `Policy::load()` resolves a pin to
  `<manifests_dir>/<name>.json`, while the disposition loader keys entries by
  file basename and the capability generator keys them by the manifest's own
  `"name"` field. Nothing reconciles a disagreement between the two, so keep
  `"name"` and the filename identical — a mismatch surfaces as a confusing
  disposition-coverage error, not as a clear "your name field is wrong".
- **`dispositions.json` is excluded from manifest globbing** everywhere it
  matters. It is registry data about manifests, not a manifest.
- **A `duo-` prefix marks a synthetic fixture.** The capability generator
  classifies plugin execution for any `duo-*` manifest as `synthetic-fixture`
  rather than `unmodified`. Use it for test adapters; never for a real plugin.
- **Interpreter and regenerator code ships with the manifest, not the engine.**
  A declared interpreter name resolves to `manifests/interpreters/<name>.php`
  and must define `\Duo\Interpreters\<CamelCase(name)>` with
  `post_meta_rule(string $key, array $allMeta): ?array`; it may additionally
  define `term_meta_rule()` and `user_meta_rule()` with the same signature and
  nullable-defer semantics. A regenerator, declared under a post type's
  `regen_dependency`, resolves to `manifests/regenerators/<name>.php` and must
  define `\Duo\Regenerators\<CamelCase(name)>` with
  `regenerate(int $localId): void`. A missing file is a loud load-time error
  naming the exact path.

That last one is worth stating without euphemism: **Duo loads PHP shipped
inside the manifests directory today.** The trust argument is not that it
doesn't — it is that the manifests directory is operator-controlled and deploys
with the agent itself, so loading code from it is the same trust decision as
running the agent at all. That is a real boundary, and it is the honest
description of where things stand.

## The minimal worked example

[`manifests/contact-form-7.json`](../../manifests/contact-form-7.json) is about
as small as a real adapter gets. Stripped of its notes, it is six keys:

```json
{
  "spec_version": 2,
  "name": "contact-form-7",
  "plugin": "contact-form-7/wp-contact-form-7.php",
  "version_range": {"min": "6.0.0", "max": "7.0.0"},
  "notes": ["…"],
  "post_meta": {
    "_form": {"class": "authored"},
    "_hash": {"class": "authored"}
  }
}
```

- `spec_version` must equal the engine's own `DUO_SPEC_VERSION` **exactly**.
  Absent and declared-wrong are the same failure, both refused at load.
- `plugin` is the plugin basename; `version_range` is `{min, max}` with min
  inclusive and max exclusive, checked with two `version_compare()` calls. One
  plugin per manifest. Declaring a plugin without a well-formed range is
  refused at load, before any target contact — there is no "latest" or
  unbounded form, because an unbounded claim is not certifiable. Two pinned
  manifests naming the same plugin with different ranges is also refused
  outright: manifest precedence must never depend on pin order.
- `notes` is where the *evidence* for every rule lives. Read CF7's: each entry
  names what was verified live, against which version, through which code
  path. That is the standard. A rule without an evidence note is a guess with
  better formatting.

### The caveat that catches everyone

CF7's own notes carry it: `wpcf7_contact_form` **must** be added to the site's
`policy.post_types` in `site.duo.json` for any of these rules to take effect.
**Manifests cannot declare post-type scope.** A manifest classifies keys within
entities that are already in scope; the scope list itself is site-local policy.
Ship a manifest for a plugin with a custom post type and you have shipped half
the answer — the site still has to opt its entities into management.

For an interpreter-shaped adapter, where meta semantics live in data rather
than in a static key list, [`manifests/acf.json`](../../manifests/acf.json) is
the reference.

## Precedence, in one sentence each

- **Site policy always wins.** A rule in `site.duo.json`'s `policy` outranks
  every manifest, for every section.
- **A non-core manifest outranks `core`.** The loader keeps scanning past a
  `core` match specifically so a plugin's own declaration takes it — a
  reclassification of a core option by a plugin manifest is legal and loud.
- **Patterns are the last resort.** `option_patterns`, `meta_patterns`, and
  their kin are consulted only after every exact rule has missed.
- **Anything still unmatched is unclassified**, which is a loud abort, not a
  default.

## The authoring loop

### 1. Observe

Exercise the plugin on a real environment — create the entities through the
plugin's *own* admin code path, not by hand-writing postmeta, because the
whole point is to learn what the plugin actually writes. The provenance journal
records those writes; `wp duo journal-report --manifests=<names>` aggregates
them and scores proposals against the manifests you already have.
`wp duo journal-reset` truncates the journal when you want a clean observation
window for one specific interaction.

Note what the journal will *not* do: a bare authenticated write never proposes
`authored`. Proposals come from evidence and are deliberately conservative.

### 2. Propose

```sh
duo pending dev
```

The review queue shows unclassified meta on in-scope entities, entity types
with live rows and no scope disposition, and journal-observed unclassified
options. Each item carries whatever evidence exists — entity counts, journal
surfaces, a ref-hint, a secret flag — and **never a guessed classification**.
An item with no evidence for a proposal prints `-`.

### 3. Draft

```sh
duo classify dev
```

Interactive triage reads decisions from stdin, so it is pipe-testable. Under
the hood every decision batches into a single agent call, and that call has two
wp-cli parsing traps that were confirmed empirically rather than assumed:

- **Use the `=` form.** `--set=post_meta:foo=runtime` works;
  `--set post_meta:foo=runtime` does not — wp-cli parses the space form as a
  bare boolean flag and the value lands in positional arguments, silently.
- **Repeating the flag does not accumulate.** `--set=a --set=b` keeps only
  `b`. Pass multiple rules as one semicolon-joined value:
  `--set='post_meta:foo=runtime;options:bar=authored,ref=post'`.

Both apply whenever you drive `wp duo classify` directly. `duo classify` builds
the joined value for you.

### 4. Export the site-local rules into a manifest

```sh
wp duo policy-to-manifest --repo=<path> --match='^wpcf7_' --name=contact-form-7
```

`--match` is a PCRE body without delimiters, tested against each key; `--name`
becomes the manifest's `name` field. This exports **only site-local policy
rules** — the ones you just classified into `site.duo.json`. It is the
promotion path from "one site decided this" to "the library declares this",
and it is deliberately one-directional: nothing reads a manifest back into site
policy.

Move the emitted JSON into `manifests/<name>.json`, add `plugin`,
`version_range`, and the evidence notes by hand, and drop the now-redundant
site-local rules from `site.duo.json`.

### 5. Pin it

```sh
wp duo manifest-pin --name=contact-form-7
```

This prints the exact canonical `{"name": …, "digest": …}` object to paste into
`site.duo.json`'s `manifests` array. The digest is content-addressed against
the same per-manifest digest compiled artifacts record in `resolved_adapters`,
including a declared interpreter's name and bytes, and load refuses a mismatch
before any policy consumer or target contact.

`manifest-pin` deliberately does **not** load `site.duo.json`. A stale declared
digest must never prevent you from computing the reviewed replacement.
Updating a pin is an explicit review act; it is never automatic.

### 6. Exercise it

Re-run the loop on a clean environment: capture, apply to a second environment,
recapture, and diff. A round-trip whose recaptured `state/` is byte-identical
is the only evidence that the classification is right. `wp duo lint` is the
companion check — it flags id-shaped values at undeclared paths, which is
exactly the shape a missing `ref`/`json_refs` declaration takes. Its findings
are plan-time signals, not proof of corruption; each carries its own caveat
note, because small ids legitimately coincide with counts, versions, and
ordering indexes.

Real worked narratives, with the empirical grounding for each decision, are in
[docs/grind/r1a-forms.md](../grind/r1a-forms.md) and
[docs/grind/r1c-agency.md](../grind/r1c-agency.md).

## Dispositions and the capability registry

`manifests/dispositions.json` is separate from every manifest **so that
declaration cannot imply certification**. It has exact one-for-one coverage of
the shipped manifest files — a manifest with no disposition entry, or an entry
with no manifest, is a loud load failure — and classifies each `certified`,
`experimental`, or `excluded`, naming supported versions, entity and field
sections, operations, lifecycle phases, deletion semantics, explicit
unsupported behavior, and every table whose default keyspace is authored.

Certified entries cite named tests in the certification bundle, and the bundle
refuses a certified evidence reference that is not present in that run. So a
`certified` claim requires all of: a current evidence bundle, byte-matched
plugin and version-range facts, and a passing named test.

`manifests/capabilities/registry.json` and
[docs/capabilities.md](../capabilities.md) are the **generated** projection of
all that, produced by `scripts/capability-registry.php` and byte-compared by
`make release-gate`. Never hand-edit either, and never restate their contents
in prose — a hand-copied certification claim is exactly the failure mode the
separation exists to prevent.

Capability *reduction* is a legitimate outcome of this process. Behavior that
works but cannot be proven is removed and refused rather than shipped
under-proven.

## Planned: what an adapter cannot express yet

Everything in this section is unshipped. It is here so you can recognize the
shape of a problem a manifest cannot currently solve, and route it rather than
work around it.

- **Structured native actions and plugin-owned providers** are **Planned
  (DUO-3338)** — not yet shipped. There is no `actions` manifest key, no
  `NativeActions` implementation, and no `providers/` directory at this commit.
  A plugin-specific behavior that needs to *run* rather than be *declared* has
  no first-class home yet.
- **Adapter discovery, trust tiers, and a public capability catalog** are
  **Planned (DUO-3339)** — not yet shipped. Today every manifest in the
  operator-controlled directory carries identical trust; there are no tiers and
  no shims.

What ships today in that space, and what you should reach for instead:
`rebuilders` (declared wp-cli commands run in the rebuild pass after a
non-empty apply), `regenerators` (manifest-shipped PHP for per-entity derived
rebuild), and `interpreters` (manifest-shipped PHP for schema-driven
classification). Between them they cover most of what "the plugin needs to do
something" turns out to mean in practice.

### One naming trap

The word **provider** already has three unrelated shipped meanings in this
codebase: bounded provider-resource *selectors* in the spec, the target-owned
*recovery* providers of the SSH verified-rollback profile (exclusion,
checkpoint, code-release, upload, effect), and one attachment filter. None of
them is the plugin-owned adapter provider that DUO-3338 will introduce. When
you read "provider" in an error message, check which one before you go looking
for a manifest key that does not exist.
