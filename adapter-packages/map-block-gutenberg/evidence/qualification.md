# Map Block 1.35 qualification notes

Observed 2026-09-13 (Asia/Dhaka). This is a reviewer-facing run note, not a
certificate, sealed evidence bundle, or authority to promote the adapter.

## Exercised candidate

Source commit: `bdde0ad477ef0625fdc36a877cb798cc88c290aa`.
The clean-source gate verified that exact commit before resetting the disposable
`codexmap` pair (ports 8996/8997). WordPress was 7.1, PHP 8.3.33, with the
shared `mariadb:11` sandbox database image. The exact plugin ZIP and its SHA-256
are in `artifacts.lock.json`; no live service credential was used.

```sh
WPRISM_SOURCE_ROOT=/path/to/clean/worktree \
CONF_EXPECTED_SOURCE_SHA=bdde0ad477ef0625fdc36a877cb798cc88c290aa \
CONF_PAIR=codexmap CONF1_PORT=8996 CONF2_PORT=8997 \
  bash sandbox/conformance/run.sh map-block-gutenberg
```

Terminal result: `AGENT ROUNDTRIP PASSED (map-block-gutenberg; production promotion withheld)`.
The host deployment actually refused before promotion-begin because the adapter
is experimental. Only the documented lower-level agent verbs exercised target
activation and authored apply; no certification status was changed.

The executable capsule hooks established:

- Default and explicit historical source keys disappear from canonical state.
  Malformed source capture refuses without publishing partial state; restoring
  exact source content/timestamps allows byte-identical recapture.
- A fresh target creates the explicit-key page as ID 7002. A pre-existing,
  unmanaged same-slug page is explicitly adopted at ID 7001, different from its
  source identity. Both iframe and attribute keys use the target binding.
- Missing intended provisioning and physical/intended key drift produce the
  exact typed `apply_refused` envelope. The pre-existing page's complete native
  row hash is unchanged after each refusal.
- A target-only runtime option survives. Repeated apply reports zero applied,
  created, updated or drifted entities, no warnings, and passing verification.
- The native frontend follows WordPress's canonical permalink redirect and
  contains the target iframe, with neither source credentials nor `@env`.
- An authored height change updates exactly one entity. Restoration and a
  final capture reproduce the entire source state tree byte for byte.

## Native editor acceptance

After that run, the real target editor loaded the installed 1.35 JavaScript.
Authenticated `wp.apiFetch` read both fixture pages with `context=edit`;
`wp.blocks.parse`, `serialize`, and `parse` inspected their actual stored content.
Both pages contained exactly one map. Both initial and reserialized maps were
valid, their `api_key` equalled the target fixture key, and the block registration's
default equalled that key. Neither source key nor canonical marker was present.
Reserialization was in memory; this is not a claim of a subsequent editor save.

The Playwright-driven native inspection caught the missing outer wrapper class
and apostrophe-escaping defects that a PHP-only fixed-point test missed. The
six native saver fixtures retain those cases. The final browser check blocked
Google requests; an earlier synthetic-key browser request returned HTTP 403.
Maps API validity, billing, restrictions, and availability are not qualified.

WordPress emitted four upstream JavaScript warnings: legacy block API version,
two iframe stylesheet-enqueue warnings, and deprecated InspectorControls.
There were no invalid-block results. These warnings are not represented as a
clean native compatibility certificate; upstream compatibility review remains.

## Offline and repository gates

The capsule has three dynamically discovered offline suites, 214 assertions:
167 credential/materialization assertions, 14 package-contract assertions,
and 33 runtime-admission assertions. Shared `regress-block-content-codecs`
adds 47 assertions for the negotiated engine primitive and locked bindings.

The late-SQL-failure fixture uses the real post materializer, canonical compiler,
option binding reader and transaction machinery with the shared row-backed
database fake. It observes the first bound iframe write, fails the second post
update, and verifies complete rollback of posts, metadata, identities and options.
Retry and repeated materialization pass. This is deterministic transaction
evidence, not native process-crash or competing-connection evidence.

On PHP 8.3.33, `make release-gate`, PHP/shell syntax checks, PHPStan and changed-file
formatting pass. The full `make regress-offline-all` equivalent
(`php tools/offline.php -j8` on stock macOS Make) passes 384/387 suites and retains
three failures already
reproduced on clean base `69df0065`: `regress-disposition-split`,
`regress-manifest-dispositions`, and `regress-spec-v3-digest-neutrality`.
`composer check` runs 1,354 PHPUnit tests (38,034 assertions), with one failing
test: `BrandIdentityTest`'s two retired-identity findings in
The Events Calendar's conformance script. No sibling adapter bytes or historical
baseline expectations were changed to hide those failures.

## Promotion prerequisites

`production-readiness.json` keeps every unqualified family visible. Remaining
work includes native dependency/lifecycle transitions, unsupported-deletion
preservation, process interruption/recovery, controlled native concurrency, and
unsupported-platform/unavailable-API assertions. Block removal inside an owning
post is an authored post update, distinct from a plugin-owned deletion grant;
this adapter creates no plugin entity or credential deletion authority.

Human review of `block-content-codecs/v1`, its public-text clearance boundary,
and the capsule disposition is still required. The full local merge gate must
also become green. Neither successful tests nor this note certify the adapter.
