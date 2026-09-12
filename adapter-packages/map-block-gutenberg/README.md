# Map Block for Google Maps

Experimental pending production qualification and review. Exact audited plugin:
`map-block-gutenberg` 1.35, SHA-256 pinned in `evidence/artifacts.lock.json`.
The enforced compatibility window is `>=1.35 <1.35.1`; 1.34 is a refusal fixture.

## Transport contract

The adapter captures native `webfactory/map` leaf blocks in posts and block
widgets. Its four attributes are `zoom`, `height`, `address`, and `api_key`.
A map may be nested inside ordinary parent blocks, but cannot contain children.
Custom classes/styles/metadata, custom HTML, malformed values, and unknown fields
refuse rather than being silently discarded. The inspected native bounds are
zoom 1..21, height 50..1000, and a UTF-8 destination of at most 8192 bytes.
The destination is reviewed public text; value-level PII and secret checks remain.

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

Keys must contain 1..64 ASCII letters, digits, underscores or hyphens. The
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

The `agent-roundtrip` conformance profile explicitly proves host deployment
still refuses the experimental disposition, then uses documented lower-level
agent verbs for target qualification. It never marks the adapter certified.
The profile exercises clean creation, unmanaged same-slug adoption with different
IDs, missing/drifted target keys, preservation of a target-only runtime option,
authored updates, repeated apply, native frontend/localization, capture failure
recovery, and whole-tree recapture. It does not contact Google or assert the
validity, billing state, restrictions, or availability of the Maps service.

`evidence/production-readiness.json` is the authoritative remaining-work ledger;
a successful fixture or schema check alone cannot promote this adapter.

```sh
php tools/adapter-package-validate.php --adapter=map-block-gutenberg
php tools/adapter-package-tests.php --adapter=map-block-gutenberg
make regress-block-content-codecs
# Use a clean source commit and an allocated disposable pair:
CONF_EXPECTED_SOURCE_SHA=<commit> CONF_PAIR=<pair> CONF1_PORT=<port> CONF2_PORT=<port> \
  bash sandbox/conformance/run.sh map-block-gutenberg
```
