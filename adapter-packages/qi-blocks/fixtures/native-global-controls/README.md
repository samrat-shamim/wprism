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

These are source-shape observations. The current closed Qi roster correctly
refuses these still-undeclared attributes. They do not qualify target Apply,
editor round-trip, pattern bindings, notes, other metadata, or production
readiness. Their exact semantics must be declared through shared bounded value
contracts before admission; no implicit global exception is authorized.
