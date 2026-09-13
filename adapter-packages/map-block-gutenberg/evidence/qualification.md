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

## Promotion prerequisites at the initial candidate

`production-readiness.json` keeps every unqualified family visible. Remaining
work includes native dependency/lifecycle transitions, unsupported-deletion
preservation, process interruption/recovery, controlled native concurrency, and
unsupported-platform/unavailable-API assertions. Block removal inside an owning
post is an authored post update, distinct from a plugin-owned deletion grant;
this adapter creates no plugin entity or credential deletion authority.

Human review of `block-content-codecs/v1`, its public-text clearance boundary,
and the capsule disposition is still required. The full local merge gate must
also become green. Neither successful tests nor this note certify the adapter.

## Readiness checkpoint: native lifecycle, deletion and contention

A second clean-source run on 2026-09-13 exercised
`3aa5865bb7d17623d39a3109e6cbaa398ed310ee` on the disposable `codexmapready`
pair, ports 8996/8997, using the same WordPress 7.1/PHP 8.3/MariaDB 11 sandbox
profile. The exact-source gate passed before reset and startup. The run ended
with exit zero and `AGENT ROUNDTRIP PASSED`; production promotion remained
withheld. Its log spans 442 seconds and is retained at
`sandbox/tmp/map-ready-native-3aa5865b.log` in the qualification worktree.

```sh
CONF_EXPECTED_SOURCE_SHA=3aa5865bb7d17623d39a3109e6cbaa398ed310ee \
CONF_PAIR=codexmapready CONF1_PORT=8996 CONF2_PORT=8997 \
  bash sandbox/conformance/run.sh map-block-gutenberg
```

The additional native assertions establish these bounded claims:

- Dependency/lifecycle: deactivation/reactivation, missing code, an actually
  activated wrong basename, an unreadable header, synthetic 1.34/1.35.1 header
  faults, replacement with the exact 1.35 ZIP, native uninstall residue, and
  exact reinstall. Only 1.35 is supported, so there is no second supported
  release to exercise as an in-range upgrade. The lower-header control is an
  out-of-range refusal, not a claim that the real 1.34 artifact was downgraded.
- Refusal ordering: unreadable/missing desired code stops at the code-version
  baseline guard; deactivation reaches authored `active_plugins` drift;
  readable out-of-range headers reach `code_mismatch`. Each ordinary Apply
  refusal requires the complete case-specific public payload and one fresh,
  complete private cause graph, plus independent capability/native premises.
- Preservation: complete posts, postmeta, identity-map and state rows, plus
  the credential and target-only runtime option, survive those lifecycle
  controls. Reinstall yields a clean no-op Apply and exact full recapture.
- Unsupported credential deletion: a valid `gmw-map-block-key` tombstone
  refuses in compile and Apply, including both deletion flags and forced
  conflict/reference overrides. Physical credential data, its environment
  intent file, the prior compiled artifact and the complete canonical tree
  remain unchanged. Removing the rejected intent restores a clean no-op.
  No plugin-owned entity/table exists; owning-post deletion remains core's
  responsibility, and block removal is an owning-post update, not a new
  credential or independent map deletion grant.
- Concurrency: the existing test-only capture barrier proves real overlap
  while same-destination capture, different-destination capture and Apply
  each return their exact lock/fence refusal. Native rows, credentials, intent,
  prior artifact and canonical state survive. Releasing the holder produces
  one exact capture; the other destination retries successfully and Apply is
  a clean no-op. The controller verifies holder completion and marker cleanup;
  it does not require a transient release marker to outlive the holder.

Six capsule-local offline suites now provide 460 assertions, including hostile
transport, missing-premise, preservation and release-race controls. Independent
agent review found and closed the archive invocation, public-payload admission
and release-acknowledgment defects in the test harness. The native run also
caught incorrect expected refusal ordering; the deterministic tests now pin
the earlier product guards rather than accepting a generic refusal as proof.
Shipped package bytes and adapter digest remain unchanged:
`e814fa810757d428dfabc5479e217c5313eb0d8e7ca4ca25da59403ebaba1c8c`.

The disposable pair's databases, containers and webroot volumes were destroyed;
logs, private transports and site-repository snapshots were retained under
`sandbox/tmp/`. No other actor's pair was changed.

This checkpoint supersedes the initial remaining-work list for dependency,
lifecycle, unsupported credential deletion and the described contention cases.
Process interruption/durable recovery and unsupported-platform/unavailable-API
qualification remain gaps. No new native editor save, real Maps service
credential, platform sweep or human approval was obtained. Shared-codec,
public-text clearance and disposition review are still required; readiness
remains `unready` and the disposition remains `experimental`.
