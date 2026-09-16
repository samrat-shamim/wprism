# Native attribute roster

`roster.json` records the exact free Qi Blocks 1.5.2 artifact's attribute names
and type spellings. Shipped JavaScript `registerBlockType` declarations and the
eleven PHP block constructors were evaluated read-only in isolated stubs. No
render hook, editor control or managed-site write ran during that extraction.
The artifact hash is the capsule's existing locked 1.5.2 ZIP hash. Native type
spelling, including four upstream `" number"` typos, remains visible.

The old inventory covered string/container fields but omitted numeric and
boolean fields. Its 7,497 value rules remain unchanged; the explicit roster adds
14,008 names, using the same shared plain-data codec that preserves native
numbers, booleans and empty defaults. These declarations do not impose scalar
type predicates or claim successful editing of every control.

The complete manifest has 21,505 attribute rules: the 21,456 shipped Qi schema
fields plus WordPress's supported `className` attribute on each of 49 blocks.
All 49 block rosters are closed by negotiated `block-attribute-closure/v1`.
An unknown top-level attribute refuses Capture, immutable compilation and Apply;
Lint reports its trusted block owner without exposing the unknown key or value.
Nested values continue to use their separately declared codecs.

The capsule regression exercises every new scalar field and every closed block
owner. Native behavior evidence still comes from the retained Save/reopen
fixtures and scoped live workflows, not from schema extraction.
