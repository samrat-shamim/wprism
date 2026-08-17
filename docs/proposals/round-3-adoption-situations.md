# Round 3 T7 — ten progressive-adoption situations (plan)

*Status: owner-directed (2026-08-18): "once the ongoing work is done, do several
rounds of grinding where a simulated end user adopts duo progressively in 10
different situations combining different published themes, plugins, custom
plugins, edge cases combinations." Every gap a situation hits is fixed, not filed.*

## Shape

One driver, `sandbox/tests/grind_adoption.sh`, ten situations, each a fresh pair
reset; the same `grind_mup.sh`/`grind_adapter_walk.sh` conventions
(`--self-check`, `--dry-run`, evidence dir per situation, exact PASS string, one
dedicated pair, `DUO_EXPECTED_SOURCE_SHA`, `ADOPT_SITUATIONS=A1,…`). "Progressive"
means each situation is a *sequence the same operator would actually take*: they
start with the smallest thing Duo can do for that site (`duo doctor`, `duo assess`
read-only), and add management one decision at a time — never a big-bang init.
Every situation ends with the full loop where the site allows it (rehearse →
capture → merge → release → verify → recover) or with the typed refusal that says
why not, asserted by reason code.

Subjects available without new pins: WooCommerce 11.0.0 / 10.9.4 (below range),
WPForms Lite 2.0.0.4 (unmanifested), Yoast 27.9/28.0/28.2, Polylang 3.4.5/3.5/3.8.6,
Elementor 3.35.9/4.0.0/4.2.2, ACF 5.12.6/6.0.0/6.8.7, CF7 5.9.8/6.0.1/6.1.6, Ninja
Forms 3.x, Paid Memberships Pro 3.8.3 (experimental manifest), themes Twenty
Twenty-One 2.8 (classic) and Twenty Twenty-Five 1.5 (block/FSE); custom plugins
`sandbox/fixtures/acme-catalog` (bundled adapter) and `duo-agency-cpt`. New pins
this train may add: one classic third-party theme (e.g. Astra) and one
"unmanifested + custom table + cron" plugin (e.g. Redirection) — added as
`exercise-fixture`.

## The ten situations

| # | site | progression the operator takes | what it must prove / where it should stop |
|---|---|---|---|
| A1 | brochure site: classic theme (2021), core only, a few pages + menu + widgets | doctor → assess → init → contract → rehearse → edit page → release → verify → recover | the smallest honest loop; zero unknowns; every readiness `Ready/Platform-certified` |
| A2 | block theme (2025, FSE): templates, template parts, navigation, patterns | doctor → assess (fse profile) → init → edit a template part on preview → release → verify | the FSE profile end to end; theme code travels via the code half; template edits round-trip |
| A3 | shop: WooCommerce + classic theme + orders/customers already present | assess (orders/customers `runtime/preserve local`) → contract → release a catalog change while live orders keep arriving on the target | the operational-state boundary is literal: post-checkpoint orders survive a release and are lost only on an explicit recover, exactly as the claim says |
| A4 | shop + SEO + forms: WooCommerce + Yoast + CF7 (all certified) | assess shows three certified adapters; release touches all three surfaces; verify journeys for shop + a form page | multi-adapter composition, cross-manifest guards, one contract |
| A5 | multilingual shop: WooCommerce + Polylang | languages/translations captured; a translated product edit released; polylang_mo/string translations named honestly | polylang's non-public `polylang_mo` post type: declared or left local *by decision*, never silently |
| A6 | builder site: Elementor + ACF + block theme | ACF field groups + Elementor templates/kit in scope by decision; a design edit round-trips | the "manifests cannot declare post-type scope" half-answer is walked through as an operator would (`elementor_library` opt-in) |
| A7 | unmanifested published plugin (WPForms Lite) on A4's shop, kept unmanaged, then adopted with a site adapter | T6 S1 then S2 on a busier site | the same journey holds beside three certified adapters |
| A8 | custom in-house plugin (acme-catalog) + WooCommerce, adapter bundled, promoted, certified, then a code release that changes the plugin AND its adapter | T6 S3 plus: bump the plugin (new option), update `duo-adapter.json` and `adapters/acme-catalog.json`, re-certify, release code + state together | adapter and code evolve together through the loop; the pin/digest changes are the operator's explicit acts |
| A9 | version edges: WooCommerce 10.9.4 (below range) → upgrade to 11.0.0 mid-adoption; Yoast 27.9 → 28.2 | assess refuses `Requalification required`/version mismatch honestly; upgrade; requalify; release | version windows and requalification words as an operator meets them |
| A10 | edge cases on one site: an already-adopted repo (`duo adopt` over SSH-less pair → refusal), multisite refusal, a hard-matched secret in an option the operator wants authored (`--allow-secret`), a plugin deactivated after adoption, a theme switch (2021 → 2025) after adoption, an out-of-tree adapter that shadows a shipped name (T6 S4) then is removed | each stops or proceeds by the documented rule; the recovery claim stays literal through all of it | the refusals are typed and remediations are actionable; nothing is silently skipped |

## Rounds

Round 1: build A1–A5, run, fix product gaps, one iteration certification per gap
batch (core + the pinned subjects). Round 2: A6–A10. Round 3: rerun all ten on the
final certified tip as the acceptance; add the two new pins if a situation needs
them. Each round ends with a full 9-subject certification and a PR.
