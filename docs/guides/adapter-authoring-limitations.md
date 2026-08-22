# Adapter-authoring limitation ledger

This ledger records plugin state shapes that the current generic grammar cannot
represent faithfully. An entry is a platform boundary, not a plugin-specific
branch request: the remedy must be a reusable primitive with adversarial
coverage before any affected adapter is promoted.

The source probes below used official WordPress.org artifacts on 2026-08-22.
They are deliberately separate from `manifests/dispositions.json`: a rejected
candidate is not shipped adapter identity and makes no capability claim.

## WPForms Lite 2.0.0.4 / 2.0.0.5

- `wpforms` post content is JSON and includes the form's source-local numeric
  `id`. `body: "verbatim"` preserves the wrong id on a target whose post id
  differs; it does not provide fidelity.
- The `wpforms/form-selector` Gutenberg block persists `formId` as a JSON
  string. The current block-reference codec accepts `int|int[]` and writes an
  integer back, changing the stored type even if the identity resolves.
- Forms also use the `wpforms_form_tag` taxonomy. A forms-only post-type draft
  omits authored plugin state.

Required platform work: structured post-body reference paths, a type-preserving
string-id attribute codec, and a complete taxonomy/delete-scope exercise. The
WPForms site adapter in `docs/grind/adapter-walk.md` remains an authoring and
trust-flow fixture only; it is not a platform support claim.

## Redirection 5.9.0

- `redirection_items.action_data` can be a PHP-serialized array containing a
  target URL. Typed-table authored strings currently pass through
  `tokenize_text()` as opaque bytes. Replacing a source URL can change its byte
  length without updating PHP serialization length prefixes, producing invalid
  data on capture.
- Group/item raw writes also bypass `Red_Module::flush()`, so generated module
  state needs a bounded postcondition rather than an exit-code-only callback.

Required platform work: structured typed-table column codecs that decode,
tokenize string leaves, and re-encode canonical PHP serialization, plus a
verified module-flush provider. Until both exist, no Redirection manifest is
shipped.

## Custom Post Type UI 1.19.3

- `cptui_post_types` and `cptui_taxonomies` are nested authored arrays. The
  `menu_icon` field may be a site URL, but a plain array-valued option with no
  reference declaration does not currently tokenize nested string leaves.
- Duo writes options after WordPress `init`; CPT UI registers its dynamic post
  types and taxonomies during `init`. Newly applied definitions therefore do
  not exist in the same process that would need them for entity materialization.
- A taxonomy `default_term` produces `default_term_<taxonomy>` with a local term
  id. The option name is dynamic and the value is derived/reference-shaped;
  treating it as ordinary authored data would leak a local id.

Required platform work: an explicit structured-leaf text codec independent of
fake reference paths, a verified post-apply type-registration/process boundary,
and a generic dynamic derived option-name reference rule. Until those exist, no
CPT UI manifest is shipped.

## Shipped experimental adapters with open apply work

- Code Snippets 3.9.6: typed rows and shortcode refs are representable, but
  direct table writes bypass the plugin's object-cache cleanup and optional
  flat-file execution rebuild. `manifests/code-snippets.json` therefore does
  not claim apply.
- WPS Hide Login 1.9.19: both route slugs are representable, but the plugin's
  own settings path flushes rewrite rules. A clean-target request matrix must
  establish the required postcondition before apply is claimed.

These are explicit promotion blockers in `manifests/dispositions.json`, not
silent caveats. `conformance-ecosystem-adapter-batch` exercises their exact
artifacts through capture, compile, plan, deterministic recapture, and live
plugin readback only. Its `capture-plan` mode stops before target mutation, so
none of these entries claims apply.
