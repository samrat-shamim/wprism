# Adapter constraints and remaining limits

Start with [the adapter authoring guide](../guides/adapter-authoring.md). This page is the detailed reference for this part of the workflow.

## Extension channels and remaining limits

Four shipped channels cover what a plugin needs to *do*: **native actions** for
core-owned operations, **providers** for plugin-owned ones, **regenerators**
for per-entity derived rebuild, **interpreters** for schema-driven
classification. What is still missing sits above them.

**Executable adapter packages, compatibility shims, and a public capability
catalog** are **Planned**. Site-repository discovery, plugin-bundled discovery,
packaged installation (into the site source, with a signed certificate),
derived trust tiers, loud unsigned support, agent-authority signed evidence,
and — since WP-5.6 — **remote discovery and distribution** all ship now. What
remains absent is any way for an out-of-tree adapter to introduce executable
code outside an installed plugin; do not work around that boundary with
manifest fields or copied PHP.

**Remote discovery and distribution: what shipped, and what did not.** `wprism
adapter discover|install|update` reads a `wprism-adapter-index/v1` document —
`{adapters, format}`, each entry `{adapter_sha256, agent_versions,
authority_fingerprint, certificate_sha256, certificate_url, url, version}` —
and that is the mechanism that tells you an adapter you do not already have
EXISTS. Installing one writes exactly `adapters/<name>.json` plus
`adapters/certifications/<name>.json`, so a distributed package is not a fourth
source: it is the site source, and everything above about pins, certificates
and claims applies to it unchanged. Resolution never falls through — an
unpinned version, a digest that does not match the fetched bytes, an
unreachable URL, an out-of-window package, an unsigned or unverifiable one, or
a signer your repository has not enrolled each refuses, and verification runs
in a staging root so a refusal leaves your repository byte-for-byte as it found
it. Nothing under `agent/` reads the format; installation is an operator act on
the host, never a runtime fetch.

Two boundaries inside that, stated rather than implied. **The index carries no
signature** and confers no trust: it is a pointer document, every entry is
digest-pinned, and every trust decision is re-derived from the fetched bytes
against your own `adapters/authorities.json`. A tampered index can deny you a
package; it can never install one. That means an index cannot enroll its own
signer either — you enroll a vendor key deliberately, or the install refuses
with `[authority_not_enrolled]`. **Exactly one transport ships, `file://`.** An
`https://` entry is discoverable and refuses at install naming the mirror step:
a network fetcher no offline suite can exercise is an unevidenced supply-chain
surface in the one command whose job is to refuse unevidenced bytes. Mirror the
two files into a directory you control and point an index at them. Adding an
HTTPS transport is a separate reviewed decision with its own evidence, not a
gap to be filled in. `spec/repo-format.md` § v3.19 and `docs/wire-surface.md`
R-30 carry both decisions and what would have to be true to reverse them.

What ships for the adapters you already have is the **installed-adapter
catalog**: `wprism adapter list|inspect|doctor` reports the two host-reachable
sources offline (and `wp wprism adapter-survey` all three, on the target),
printing each adapter's derived trust tier — `declarative_manifest`,
`native_action`, `plugin_provider`, or `compatibility_shim`, computed from the
privileges its own declarations actually reach and never self-declared — next
to a `tier_basis` naming the exact declaration that produced it. `doctor`
reports the discovery conditions that make every other command refuse
(shadowing, ambiguous identity, case-confusable names, an invalid identity
slug, a certificate that does not verify) as rows rather than dying on them,
each row naming which source it is about and whether it refused that whole
source or just one adapter.
Separately, `wprism plan` and `wprism status` now carry `provider_problems` rows for
every declared provider capability an environment cannot supply, with
remediation.

### One naming trap

The word **provider** carries four unrelated meanings here, all shipped. Three
are not the adapter provider this guide is about: bounded provider-resource
*selectors* in the spec, the target-owned *recovery* providers of the SSH
verified-rollback profile (exclusion, checkpoint, code-release, upload,
effect), and one attachment filter. Check which one an error means before
hunting for the wrong contract.


### Scalar settings inside a shared option

Use `scalar-option-constraints/v1` when a native consumer requires a strict
scalar type or a finite choice in an authored option subkey. For example, PHP's
`set_time_limit()` refuses a nonnumeric string even though an untyped option
value can compile and round trip. Declare the consumer contract at the value
boundary:

```json
"engine_features": ["scalar-option-constraints/v1", "spec-window/v1"],
"options": {
  "example_settings": {
    "class": "env",
    "required": false,
    "sub_keys": {
      "seconds": {"class": "authored", "value_constraint": {"type": "integer", "minimum": 0}},
      "method": {"class": "authored", "value_constraint": {"enum": ["quick", "template"]}},
      "flag": {"class": "authored", "value_constraint": {"enum": [0, "1"]}}
    }
  }
}
```

This is a manifest fragment; retain the ordinary explicit option-autoload
contract. Derive each type, choice and bound from the native producer and
consumer, including unchecked checkbox behavior and defaults. Do not invent
ranges or normalize integers and numeric strings into one type. A predicate
validates already-native data; it never sanitizes or generates defaults.
`lint_ok` still requires its normal reviewed rationale and cannot waive an
invalid value.

A sanitizer's output range is not the consumer's valid range. For example,
`absint(0)` survives the importer plugin's settings Save, but a zero export
batch cannot advance its offset and a zero import batch misses the CSV
reader's break condition. The declaration therefore requires batches of at
least one. Trace bounds through the consuming operation and exercise boundary
progress or termination; a getter round trip alone cannot establish validity.
Keep unrelated UI limits out unless their producer/consumer contract supports
them too.

Exercise capture, host compilation, public Plan/Apply, locked target preimages,
repeat, recapture, omission, and rollback. Keep foreign module keys and runtime
state in the fixture. A damaged constrained target value refuses before write;
repair it through an independently justified native recovery path. Whole-option
SQL strings, dynamic/pattern rules and executable classification require their
own representation evidence and are outside this feature. Re-pin any capsule
whose manifest gains this declaration; the predicate is part of its identity.

### Lifecycle observations must precede native self-repair

Observe lifecycle postconditions through raw database reads or an ordinary CLI
process before opening the plugin's admin/API surface. Some plugins both skip
hook registration outside `is_admin()` and run activation again on the next
admin bootstrap when an option is absent (Importer 2.7.5, main file lines 20 and
119). An admin observer can therefore create the very tables or marker it
claims deployment created. Record the target before that bootstrap, then test
the native admin behavior separately. Observe deactivation through an actual
admin-context native request when testing the plugin's own UI-equivalent path;
plain CLI deactivation may never have registered its callbacks.
