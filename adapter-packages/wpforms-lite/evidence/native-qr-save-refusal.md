# First collected native QR Save — refused, not a readiness gate

This follows the test/evidence checkpoint merged in PR #601. WPForms Lite
remains experimental/unready with all twelve readiness families open. The
ready platform catalog remains 17/20. This record does not promote a claim.

## Exact run and retained observations

Native attempt v5 used clean source
`d363bab68660b96e4d5c276ac47fffd77c8f8240`, locked Lite **2.0.1.1** (ZIP
SHA-256 `6245074790df01a6e24a42587e024132b4a28fac499d1a8fa12ebf5580e4852b`),
owned MariaDB pair `wpfqr02` on HTTP 9546/9547, Playwright CLI **0.1.19** and
Chrome **152.0.7977.82**. The parent manifest binds the exact source, owner
PID/start/lease, browser session, configuration and sixteen producer inputs.

The native setup left Lite Connect unchecked and skipped both guided setup
and the optional challenge before opening the Builder. An ordinary Blank
Form and Single Line Text field established form **6**; native destination
pages A/B were **4/5**, with target pages **11/12**. No target Save or Apply
occurred. The actual Save button was freshly observed, and the checked-in
collector ran through its exact host-generated input file, with a separately
retained immediate DOM baseline.

The collector retained **83** complete ordered controls, one before-save and
one saved event, and **two** complete HTTP exchanges. Browser console, page
errors and collector errors were empty; body tasks reported drained. These
facts do **not** mean the run was diagnostic-free or admitted:

| Exchange | Native request | Request / response bytes | Result |
| --- | --- | --- | --- |
| 1 | XHR POST `wpforms_save_form` to owned admin AJAX | 10,073 / 162 | HTTP 200, native saved event |
| 2 | Fetch POST `/wp-json/wpforms/v1/themes/custom/?_locale=user` | 19 / 15 | HTTP 200 **with a WordPress diagnostic header** |

The second request was observed 1 ms after the collector's saved-event
timestamp; the explicit suffix closed after 251 ms. No artificial delayed
request was injected. This is not the still-required timing-negative proof.

## What the real Save invalidated

1. **Serialized controls are a list, not a unique-name map.** Native Choices
   widgets contribute three empty `search_terms` controls inside the form:
   tags, QR page, and confirmation page. A fourth search belongs to the
   separate embed wizard and is not serialized with the form. The previous
   host admission refused the actual record at duplicate-name validation
   (exit **255**). The corrected closed lane retains all three ordered
   entries, requires each empty, and rejects any other duplicate; it never
   drops these controls from before/event/POST/after comparisons.
2. **The first baseline Save is not QR-only.** TinyMCE synchronized the
   confirmation message from plain text to its `<p>…</p>` representation.
   This was the only before/after control difference. Replaying the unchanged
   record through the corrected host still refuses full-control preservation.
   Prime the complete native form through ordinary Save before capturing a
   QR-only baseline. Do not normalize the old record, waive rich-text changes,
   or mislabel this first Save as a successful destination transition.
3. **Save has a second native writer.** The locked Builder theme module
   subscribes `saveCustomThemes` to `wpformsSaved`; initialized empty custom
   data is still an object and the admin path posts it. The source is
   `assets/js/admin/builder/themes/modules/themes.min.js`, enabled by
   `src/Admin/Builder/Settings/Themes.php:122-167,260-300`.
   `src/Integrations/Gutenberg/RestApi.php:210-239` calls
   `ThemesData::update_custom_themes_file()`; its `:138-159,207-217` creates
   the uploads theme directory and writes `themes/themes-custom.json`, using
   `{}` for empty data. SQL-only snapshots cannot prove this physical effect.
   Require the exact form-plus-theme exchange pair, reject a third exchange,
   and add an independently captured complete uploads-tree witness before
   any preservation claim. Do not disable or filter the native theme hook.
4. **HTTP 200 plus empty logs can still contain a warning.** The REST response
   includes `X-WP-DoingItWrong` for the slash-delimited `/wpforms/v1/` namespace
   in `register_rest_route`. The header survives in the private record even
   though all five native consumer log observations were empty. Presence of
   that header now refuses either response, case-insensitively, even with an
   empty or duplicated value. No plugin patch, warning allowlist, debug
   suppression or engine fallback is introduced. The locked artifact's
   diagnostic remains an unresolved native-gate limitation.

The HTTP contract is capsule-owned. Existing shared private-output, complete
SQL and filesystem-tree evidence helpers supply generic test machinery; none
of these native routes, controls or allowed effects belong in engine core.
No finding invalidates the platform architecture.

## Verification and remaining work

The focused host suite uses a **synthetic diagnostic-free two-exchange**
positive fixture, not an edited copy of v5. It covers missing/reordered/extra
requests, exact empty theme payload and response, context/nonce framing,
clock/body bounds, warning headers, coherent Choices multiplicity/value
changes and unprimed rich text. REST nonce syntax and its response echo are
transport observations, not independent DOM nonce or actor proof.

Retained v5 is never made green: the original host refusal and subsequent
unchanged-record preservation refusal are both retained. A clean native
Save/HTTP result, explicit delayed-extra-request refusal, complete SQL and
uploads transitions, fresh target consumers, ordinary Capture/Plan/Apply,
zero-write retry and full compiler convergence remain unproven. Pending
requests whose response headers arrive beyond the collector suffix also need
an explicit completion/deadline test before a repeatable native gate.

## Archive and cleanup

In the retained QR worktree, native records are under
`sandbox/tmp/wpforms-qr-save.OycICY/`, and browser records under
`sandbox/tmp/qr-browser-save.oOoqzW/` with `v5-` stage prefixes. All **five**
complete database snapshots have an independent **26-table** roster and
fresh nine-field native metadata for every column. The post-Save dump is
**5,595,035 bytes**; no template-cache row or table was omitted. There is no
corresponding uploads-tree preimage, so this is not full preservation proof.

The complete private Save CLI record has SHA-256
`268769aa7c90332f2661549c6a9c13506e914a7e13cbf8300599b8ce7a659d08`;
the separate baseline record has SHA-256
`48310a13e699f6b3043d520e8871a71650c452b86a6ef421e02f7705b8e4dbeb`.
Full headers, cookies, nonces, bodies and SQL remain private. The sixteen
producer input files were hash-checked against the run manifest and archived
before changing source; `producer-inputs.tar` is mode 0600.

One read-only preparation command had a JavaScript syntax error (exit **1**)
and was retried under a new stage name; its failure remains retained. Native
logout used the actual emitted logout URL through full browser navigation.
Independent complete SQL showed one owned administrator token before logout
and no session row afterward. The exact named browser was closed. The shared
owner destroyed its pair, databases, exact site/origin roots and lease before
exiting **0** with an explicit retirement-only message, **not E2E PASS**.
No foreign pair or browser was touched. No shipped package bytes or adapter
digest changed.
