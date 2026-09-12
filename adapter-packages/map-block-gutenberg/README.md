# Map Block for Google Maps

Experimental, **not production-ready**. Audited artifact: official
`map-block-gutenberg` 1.35, SHA-256 pinned in `evidence/artifacts.lock.json`.
The compatibility window is `>=1.35 <1.35.1`; 1.34 is a refusal fixture.

The capsule classifies `gmw-map-block-key` as environment-owned and allows
capture/compile/plan of ordinary content while the plugin is active. It
refuses every `webfactory/map` block, including blocks in reusable content,
nested blocks and block widgets. No map deploy/apply capability is claimed.

The plugin's `assets/js/_source/blocks/index.js` declares four attributes:
`zoom`, `height`, `address`, and `api_key`. Its `buildMapIframe()` and `save()`
write static iframe HTML with the key in `src`. The `api_key` default comes
from `wf_map_block.api_key`, localized by `enqueue_block_editor_assets()` in
`map-block-gutenberg.php`. Gutenberg can omit that default from the comment
while retaining it in the iframe. Refusing only a present `api_key` attribute
therefore leaks source credentials. The fixture is a source-derived probe of
that native save shape with a synthetic key, not an editor-captured artifact.

The whole-block codec closes capture/materialization; the interpreter's
repository constraint closes immutable post and block-widget compilation.
Errors name the boundary without printing the key, address, or HTML.
An empty or keyless map remains refused because no map transport is qualified.

Production work needs a reviewed engine primitive to bind a target-local key
in both block attributes and saved iframe HTML without storing the source key
in canonical content. Current whole-block hooks return attributes only, and
`block_values` has no environment binding. After that primitive exists, add
real editor save/validation, clean/dirty target roundtrips, native render,
lifecycle, failure/recovery and concurrency evidence before certification.
The twelve-family readiness record keeps that work explicit.

Local checks:

```sh
php tools/adapter-package-validate.php --adapter=map-block-gutenberg
php tools/adapter-package-tests.php --adapter=map-block-gutenberg
```

Live evidence uses this capsule's capture-plan conformance entry and the
repository's disposable-pair harness, bound to a clean source commit. The
source check exercises the plugin's real editor localization, API-key
exclusion, saved-HTML refusal, restoration of the fixture edit, and deterministic
recapture. It does not contact Google or assert map-service availability.
