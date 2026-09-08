# Native selected media controls

`blocks.html` retains six block fragments authored with real media pickers and
the WordPress editor Save button on WordPress 7.1 / Qi Blocks 1.5.2, engine
`3324ff7ee7a592ae3907e3c0c68356889b3d4e53`. The source home was
`http://localhost:9176`, attachment ID 1, page ID 4. The locked plugin archive
SHA-256 is `6168357231ad0d39e41bcf71b1a0d4ad0fa08a5cc4263cc708dfe198b1f1f887`.

The first Save supplies Author Info signature, horizontal and vertical progress
bar pattern images, Image Gallery, and Image Gallery Pinterest. Its complete
saved page SHA-256 is
`8e5c8bfde480d1548db7c7b9263f1dced5afc624f1908eb7abdeccba4f6b7ebe`.
A later native picker Save supplies Image Slider. These are two source Saves,
not one synthetic registry-default document. All 51 page blocks reopened valid;
page and Qi stylesheet Save requests returned HTTP 200.

The simple media controls save six fields: `id,url,alt,caption,width,height`.
The three galleries instead save the full `wp.media` attachment response,
including nonces, user metadata, admin URLs, size caches and timestamps.
Native Capture on the named engine retained those derived gallery fields.
Four assertions in `tests/offline/regress_native_controls.php` reproduce that
defect through the real block capture path before the projection declaration.

Exactly three `nonces` objects in this fixture were replaced with explicit
`fixture-update-nonce`, `fixture-delete-nonce`, and `fixture-edit-nonce` values.
All other fragment bytes remain unchanged. Original native observations and
complete per-site policy/state/media snapshots remain private scratch evidence.
The sanitized fixture SHA-256 is
`4d26fead2c183c392f01dae7d5838dc47bcf957c07ef5540f90f714fa7bb9f86`.

The locked `assets/dist/{image-gallery,image-gallery-pinterest,image-slider}.js`
savers consume gallery `id,url,alt,caption`; separate block attributes own
layout and custom links. A separate source representation probe compared native
`wp.blocks.getSaveContent` before/after retaining just those fields: the saved
HTML was identical for all three types. Saving the projected records and
reopening produced valid blocks. Opening Edit Gallery rehydrated attachment
details, dimensions and controls through WordPress; selecting an attachment
rebuilt the full response cache. This probe supports the declaration; it is
not evidence of corrected-engine target Apply.

The initial source empty-gallery picker emitted a native Backbone URL-property
error during media-view disposal; its Save still succeeded. Fresh reopen and
the later Slider flow had no JavaScript errors and the same WordPress iframe
stylesheet warning seen in earlier source/target probes. Do not infer a clean
console or complete native qualification from this fixture.

The corrected engine's target gallery probe passes on
`4d50d712593d208e5db98bb440cb9968e0058fec`. One disposable pair executed the
committed native Apply runner's baseline and gallery phases, then held for
browser use; it did not execute that runner's later crop phase. Attachment ID
1 became 9 and page ID 4 became 12. The actual seven-entity verifier, one
content update, zero-write repeat, complete native preservation checks, HTTP
images/CSS and four complete compiled repositories pass before browser edits.

The target editor opened 51 valid blocks with four-field gallery records.
For each of the three galleries, Edit Gallery restored the checked native
attachment and its details. Select rebuilt the native response cache; real
page and style Saves returned HTTP 200. Fresh reopen retained 51 valid blocks,
and Capture again excluded response metadata without warnings. Each site's
complete policy/state/media recapture repeated byte-identically and passed
strict compiler convergence. Source and target were saved at different times:
their repositories differ in exactly the page's two authored modified fields,
so strict cross-site convergence after those independent Saves correctly
refuses. Their entire canonical bodies, styles and all other files match.

All three visible gallery images decode; their actual fetched image bytes and
decoded 1200×800 bitmap dimensions match the independent native upload census.
Slider creates two Swiper clones around its one authored image. A browser's
`naturalWidth` is [density-corrected CSS pixels](https://html.spec.whatwg.org/multipage/embedded-content.html#dom-img-naturalwidth),
so the probe checks raw bitmap dimensions separately from DOM visibility.
Both editors had zero JavaScript errors and the known iframe stylesheet warning;
the frontend had no console errors. Native Save changes page content/timestamps
and style order. WordPress creates global-style/revision rows and replaces its
autosave; complete database equality after browser use is not claimed.

The browser and owned pair were removed after retaining complete native streams,
all eight page/style request/response bodies and per-site repositories. The
[progress record](../../evidence/authoring-progress.json) identifies their hashes
and scope. Qi remains experimental: multi-image gallery behavior, other target
media controls, widgets/templates and broader readiness require their own evidence.
