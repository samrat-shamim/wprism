# Required human promotion review

Status: **pending**. There is no human approval recorded here. Passing tests
and independent agent review do not authorize a production disposition.

The exact proposed boundary remains plugin 1.35 only (`>=1.35 <1.35.1`),
static leaf maps with zoom, height, address and API key. Unknown schema,
inconsistent/custom HTML, map children and unsupported versions refuse.
Ordinary parent blocks may contain maps. No entity, table, repair action or
plugin-owned deletion grant is proposed. Exact artifacts and the native/offline
evidence are in [artifacts.lock.json](artifacts.lock.json) and
[qualification.md](qualification.md).

The human reviewer must record their identity, date, reviewed source and
written reasons for these decisions:

- Shared `block-content-codecs/v1`: review
  `agent/src/Kernel/BlockContentGrammar.php`, the whole-block path in
  `agent/src/Grammar/Blocks.php` and
  `agent/src/Apply/BlockEnvironmentOptions.php`. Confirm closed same-manifest
  ownership, bounded leaf output, no site override and locked target bindings.
  The shared evidence is
  `sandbox/tests/offline/grammar/regress_block_content_codecs.php`.
- Exact `address` public-text clearance: review the capsule manifest and
  interpreter with `BlockAttributeReader::clearance_value`. Only semantic
  key-role heuristics are removed; secret and PII value scanning remains.
  The shared suite explicitly retains email/API-key-value refusals. Map-specific
  difficult-value and native saved-content evidence remains in
  `tests/offline/regress_map_boundary.php` and `fixtures/native-editor-saves.json`.
- Credential semantics: every map, including one saved with an explicit or
  historical source key, intentionally binds the target's provisioned
  `gmw-map-block-key`. Both canonical locations contain only `@env`; missing or
  drifted target intent refuses. Per-map source keys and the plugin's bundled
  fallback are not transported or silently restored.
- Final disposition and compatibility limits: review the native editor's four
  upstream deprecation/enqueue warnings. The stored blocks passed native
  parse/serialize/parse, not a subsequent editor save. Google Maps credential
  validity, billing, restrictions and service availability are outside the
  static transport claim. The adapter-specific native profile is WordPress
  7.1/PHP 8.3/MariaDB 11, not a newly executed full platform matrix.

The data-boundary and scope-platform work-ledger rows remain blocked on these
decisions, despite their passing technical evidence. A human may approve the
bounded proposal, reject it, or require additional evidence; no choice is
preselected by this file.

After approval, the production disposition and conformance entry still need
to be updated and tested through the certified host deployment path. Any
`package/disposition.json` change moves adapter identity: normal recompilation
and re-pinning are required, followed by the capsule's live run and complete
local merge/release gates. The current digest remains
`e814fa810757d428dfabc5479e217c5313eb0d8e7ca4ca25da59403ebaba1c8c`.
