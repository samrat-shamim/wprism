# Map Block for Google Maps

Review-approved certification candidate, pending the final exact-artifact and
certified host-path gate results recorded in `evidence/qualification.md`.
Do not treat the candidate declaration as a completed release gate. Exact audited plugin:
`map-block-gutenberg` 1.35, SHA-256 pinned in `evidence/artifacts.lock.json`.
The enforced compatibility window is `>=1.35 <1.35.1`; 1.34 is a refusal fixture.

## Transport contract

The adapter captures native `webfactory/map` leaf blocks in posts and block
widgets. Its four attributes are `zoom`, `height`, `address`, and `api_key`.
A map may be nested inside ordinary parent blocks, but cannot contain children.
Custom classes/styles/metadata, custom HTML, malformed values, and unknown fields
refuse rather than being silently discarded. The inspected native bounds are
zoom 1..21, height 50..1000, and a UTF-8 destination of at most 8192 bytes.
The authorized review approves the destination's exact public-text clearance;
value-level PII and secret checks remain. See `evidence/human-review.md` for the
user-delegated AI review, rationale and limits.

The key occurs in both the attributes and static iframe URL. Gutenberg omits
`api_key` when it equals the editor default, but the iframe still contains it.
Capture replaces both locations with `@env`; apply binds the adapter's one
required environment option, `gmw-map-block-key`. Explicit historical source
keys intentionally use the same target binding. Different per-map target keys
are outside this contract. No source key is committed or compiled.

Provision the target using the existing newline-terminated stdin workflow:

```sh
wp wprism env-set --repo=/path/to/site-repo --name=gmw-map-block-key --stdin
```

Keys must contain 1..64 ASCII letters, digits, underscores or hyphens, and
cannot be the string `0` (the native editor would use its fallback). The
target's bundled public fallback is never substituted for missing provisioning.
Apply verifies intended and physical option bytes under the existing exact
option-row lock before adoption or materialization. Missing or drifted bindings
refuse with value-free guidance. Provisioning alone does not rewrite previously
saved maps; blocks materialized by a subsequent authored update use the new key.

## Evidence

The runtime uses negotiated `block-content-codecs/v1` (spec §v3.36), with no
plugin branches in the engine. Pure repository constraints reject raw credentials
and noncanonical saved HTML before publication, without WordPress.

`fixtures/native-editor-saves.json` was produced with the real 1.35 bundle and
WordPress 7.1's `wp.blocks.createBlock/serialize/parse`, using synthetic keys.
It includes defaults, explicit keys, empty destinations, Unicode, apostrophes,
and delimiter-like text. Native inspection caught two important details:
WordPress injects the block class on the outer wrapper, and apostrophes remain
literal in double-quoted iframe attributes. Offline regressions pin both.

The current `roundtrip` conformance profile requires successful certified host
deployment. Earlier `agent-roundtrip` runs proved the experimental host refusal,
then used documented lower-level agent verbs; those runs alone never certified
the adapter.
The profile exercises clean creation, unmanaged same-slug adoption with different
IDs, missing/drifted target keys, preservation of a target-only runtime option,
authored updates, repeated apply, native frontend/localization, capture failure
recovery, and whole-tree recapture. It does not contact Google or assert the
validity, billing state, restrictions, or availability of the Maps service.
The follow-up qualification adds native dependency/lifecycle refusals, exact
reinstall, unsupported credential-deletion preservation, and barrier-controlled
capture/capture/apply contention. Its exact scope and refusal ordering are
recorded in `evidence/qualification.md`. A further source-bound native run
qualifies four publication crash boundaries, tampered intent/receipt/commit-proof
refusals, durable recovery and clean retry. Capsule-local runtime/platform tests
pin unsupported topology/versions and unavailable APIs without claiming a new
native platform matrix or Maps service availability.

`evidence/production-readiness.json` is the authoritative remaining-work ledger;
a successful fixture or schema check alone cannot promote this adapter.
The exact source-bound run, native-editor observations, upstream warnings and
local gate limitations are recorded in `evidence/qualification.md`.
`evidence/human-review.md` records the authorized review and the remaining
certified host-path/exact-artifact validation. Its evidence correction identifies
an installer warning in earlier terminal-PASS logs; a warning-free native rerun
is required. Review approval alone does not establish a completed release gate.

```sh
php tools/adapter-package-validate.php --adapter=map-block-gutenberg
php tools/adapter-package-tests.php --adapter=map-block-gutenberg
make regress-block-content-codecs
# Exact positive and refusal artifacts, including the full host roundtrip:
VMATRIX_MANIFEST=map-block-gutenberg VMATRIX_EXPECTED_SOURCE_SHA=<commit> \
  VMATRIX_PAIR=<pair> VMATRIX_PORT1=<port> VMATRIX_PORT2=<port> \
  bash sandbox/tests/certify/certify_version_matrix.sh
# Use a clean source commit and an allocated disposable pair:
CONF_EXPECTED_SOURCE_SHA=<commit> CONF_PAIR=<pair> CONF1_PORT=<port> CONF2_PORT=<port> \
  bash sandbox/conformance/run.sh map-block-gutenberg
```
