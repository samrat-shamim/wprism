# WordPress global controls on Qi blocks

These seven fragments were read from the native saved page after WordPress 7.1
list-view Lock, Rename and Hide actions and the actual editor Save button on
free Qi Blocks 1.5.2. They retain the source bytes and complete page/style
readback hashes. Final native reopen showed the original Single Image name,
no visibility setting, and all lock checkboxes unchecked.

The native writer uses `lock: {move: boolean, remove: boolean}` for every saved
lock state, including both `false`. Renaming adds `metadata.name`; a mobile
visibility setting adds `metadata.blockVisibility.viewport.mobile: false`;
omission uses `metadata.blockVisibility: false`. Resetting visibility removes
that member, and resetting the final name removes `metadata` entirely.

These source-shape observations predate adapter admission. Shared bounded
value contracts now admit lock flags, strict string names and the exact
literal/object visibility shapes; the capsule's offline regression exercises
Capture/Apply and immutable post/widget compilation. Target-native Apply, HTTP,
editor round-trip and production readiness remain separate qualifications.
Pattern bindings, notes and other unreviewed metadata still refuse; no implicit
global exception is authorized.

The native Apply lane replays all seven complete saved fragments through the
real REST content writer. Each update has an independent complete body oracle,
checks the frontend owner and CSS, compares full native target-local state,
recaptures every compiled entity and repeats without writes. Its offline
admission controls cover altered elements, wrong image coordinates, unexpected
visibility and duplicate owners. This lane still needs a fresh live result;
browser controls and computed responsive visibility remain separate.
