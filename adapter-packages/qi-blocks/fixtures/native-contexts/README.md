# Native widget and template contexts

`observations.json` retains native source observations from September 8, 2026,
WordPress 7.1 and the exact free Qi Blocks 1.5.2 ZIP
`6168357231ad0d39e41bcf71b1a0d4ad0fa08a5cc4263cc708dfe198b1f1f887`.
The fixture SHA-256 is
`787c5d08549e1d8b74548fa29ef61cec3e0f7db82fb9c4ea7f28a343fd70a756`.
Its `historical_sources` records hash the four original private observation
files. These earlier source observations do not carry a recovered engine
commit binding and do not qualify a corrected target Apply or editor reopen.
No source values were sanitized or reconstructed.

The first observation comes from the Twenty Twenty-One widget editor. The
complete sidebar contains five core block widgets and a native Qi Advanced Text
widget saved with a 29px font. The three style values retain raw serialized
option bytes: widget-only; widget plus page 13; and widgets, page 13 and a
Twenty Twenty-Five site template. The last observation contains the complete
native template body, including the Qi template class and core header/footer
template parts. Only those stated fields are extracted from the original
observations; unrelated database rows and files are not part of this fixture.

The capsule's `regress_native_contexts.php` exercises actual `SidebarState`
Capture, immutable compilation and the checked widget/option transaction. All
six widget IDs diverge on the target, page selectors bind to page 813, native
PHP types and complete style bytes survive, displaced defaults are replaced,
and unrelated stored widget instances and the local template registration flag
remain intact. A late style failure rolls back both the earlier sidebar writes
and six widget identity allocations; retry, repeat and complete canonical
recapture pass. Template content runs through the block codec and compiler in
an explicit content fixture. This does not exercise theme taxonomy, native
template discovery, a post SQL writer or frontend rendering.

Two upstream outcomes must remain visible in later native qualification:

- `has_configured_global_styles()` in Qi's
  `inc/admin/global-styles/class-qi-blocks-framework-global-styles.php:382`
  skips populated `stdClass` sections. Native widget and template saves store
  those sections as objects; adding a nonempty page array enables the render
  loop. The adapter preserves those types. It cannot claim to repair the
  native guard by coercing authored option storage.
- The inline template editor submitted a compound `theme//template-slug`
  target and received HTTP 200 with `status: error` and “You are not allowed to
  update these options.” The subsequent site editor used literal `template`
  and received the complete success envelope. Both request/response records
  remain in the fixture. A successful HTTP exchange alone is not successful
  authored style storage and does not justify treating a rejected compound
  target as a portable numeric post reference.

A separate native widget run on `0d7efbd9` now exercises actual source and
target controls with WordPress 7.1, Qi 1.5.2 and Twenty Twenty-One 2.9. Native
Capture/Apply moves page 1 to 9 and widgets 2–7 to 21–26. Eight local trash rows
and twenty stored widget instances remain intact. The target editor recognizes
the saved Qi block; real font-control edits and Save work on both sites.
Complete widget and style responses match native storage. The initial sidebar
ordering request has HTTP 200 evidence, but its complete body was not retained.

The fresh run reproduces the upstream guard failure before any engine transfer:
the widget stores 29px, while its frontend renders 18px with no Qi stylesheet.
After both editors save the widget at 30px and a native page save adds a 31px
page style, both sites render those exact sizes. Complete emitted CSS matches
after the page-ID binding. No storage coercion or rendering workaround is used.

The independent widget/page edits correctly conflict on the shared style
option. The refusal preserves every observed native field. Source state already
contains the complete target widget edit; an explicit conflict decision then
applies two entities and verifies all four. Repeat Apply writes zero. Eight
complete repositories from the two transfer phases converge within their
respective phase; two further captures converge after frontend rendering.

Native boundaries remain explicit: the widget editor parks nineteen local
instances without changing any of their twenty stored settings; later target
receipts report the exact inactive-widget exclusion. The conflict decision and
removal of the theme's empty `custom_css_post_id=-1` lookup cache are reported.
Frontend access regenerates that cache, and the source editor heartbeat updates
its existing runtime lock. These exact native changes are admitted separately;
complete database equality is not claimed. Widget editors and frontends have
zero JavaScript errors or warnings; the page editor has the known WordPress
iframe stylesheet warning. The owned browser, pair and lease were removed.

Native FSE template discovery/rendering and broader widget media controls remain
open. No capability status, package declaration or adapter identity changes on
this evidence alone. Exact source, stream hashes, admission scope and initial
probe corrections are recorded in
[`authoring-progress.json`](../../evidence/authoring-progress.json).
