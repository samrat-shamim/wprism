# Grind round R1-A — a forms-driven business site (task #46)

*Own env pair `r1a1` (:8814) / `r1a2` (:8815), docker-compose profile `r1a`, journal on (`DUO_JOURNAL`). Own site repo (`sandbox/siterepo/{origin-r1a.git,r1a1,r1a2}`). Driver script: [`sandbox/tests/grind/grind_r1a_forms.sh`](../../sandbox/tests/grind/grind_r1a_forms.sh) — re-runnable, resets r1a1/r1a2 content + ledger + nf3_\* tables each run. No `agent/src/` edits (engine window closed this round).*

## Mission

A realistic forms-driven business site: **Contact Form 7** and **Ninja Forms** (both free, `wordpress.org` slugs `contact-form-7`/`ninja-forms`) on **twentytwentyone** (classic theme). Ninja Forms was deliberately chosen by the round brief: its `nf3_*` custom tables are design-review finding #8's named typed-snapshot frontier ("Ninja Forms `nf3_*` (intra-table FKs)"). This report characterizes that gap against the plugin's *real* schema (verified empirically, not from the one-line design-doc mention) rather than working around it.

## Environment facts

- WordPress 7.0.2, PHP 8.3, MariaDB 11, theme `twentytwentyone` 2.8 (classic, non-FSE).
- **Contact Form 7 6.1.6** (`contact-form-7`), **Ninja Forms 3.14.11** (`ninja-forms`), both `wp plugin install <slug> --activate` on both r1a1 and r1a2 independently (this sandbox has no code-provisioning-on-apply path — same precedent as `docs/frontier/polylang.md` and `sandbox/conformance/run.sh`'s `install_env()`).
- Neither plugin ships wp-cli commands (`wp help cf7`/`wp help nf` both fail — "not a registered wp command"), so seeding went through each plugin's own PHP API, matching the polylang.md precedent.

## Seeding method (fidelity notes, honest per the mission brief)

**Contact Form 7** — `WPCF7_ContactForm::get_template(['title' => 'Contact Us'])` (the exact static method wp-admin's "Add New" screen calls: a real default template — Name/Email/Subject/Message fields + mail settings, sourced from `WPCF7_ContactFormTemplate::get_default()`), then customized mail settings the way any real setup does immediately after creating a form (`recipient`, `subject`, `additional_headers` — a business inbox + a CC, not the placeholder admin email), then `->save()` — CF7's own public save path (`wp_insert_post` + `update_post_meta($post_id, '_'.$prop, ...)` per property: `_form`, `_mail`, `_mail_2`, `_messages`, `_additional_settings`, `_locale`, plus `_hash` added once on first save). Not hand-authored postmeta.

**Ninja Forms** — imported Ninja Forms' own bundled **"Job Application" template** (`wp-content/plugins/ninja-forms/includes/Templates/formtemplate-jobapplication.nff`, 23 fields / 3 actions — a real production multi-field business form NF ships for its own "Add New Form" template gallery) through the plugin's real import batch process, `NF_Admin_Processes_ImportForm`. This is the exact class the wp-admin importer AJAX endpoint drives — **not** a shortcut: the base class (`NF_Abstracts_BatchProcess`) genuinely terminates the PHP process after every step via `echo wp_json_encode($this->response); wp_die();`, because in real usage each "step" is a separate AJAX request the JS admin builder issues in sequence, persisting progress between requests via the `nf_doing_import_form`/`nf_import_form` options. The seed driver (`nf_import_step_php`/`import_nf_template` in the grind script) honors that protocol rather than routing around it: it invokes `wp eval-file` once per step (matching a step to a request), letting the plugin's own `restart()` path pick up where the previous step left off, until the response reports `batch_complete:true`. Two real steps were needed (one for the form/actions row, one each for a 20-field and a 3-field chunk of fields — `NF_Admin_Processes_ImportForm::$fields_per_step = 20`).

Two fidelity caveats, stated plainly:
- `NF_Admin_Processes_ImportForm::__construct()` bails unless `is_admin() && is_user_logged_in() && current_user_can('manage_options')` — true during a real wp-admin request, false by default under wp-cli. The seed script defines `WP_ADMIN` and calls `wp_set_current_user()` to legitimately satisfy that guard (simulating a real logged-in admin request) rather than bypassing the class.
- **Ninja Forms auto-creates a default "Contact Me" sample form on activation** — confirmed empirically (it exists before any seed code runs), nobody asked for it. Parallels `docs/frontier/polylang.md`'s "Polylang auto-creates content you didn't ask for" finding. Left in place deliberately (a real site builder usually doesn't bother deleting it either) rather than cleaned up, so the site carries two real NF forms.

## Ninja Forms' real schema (empirically DESCRIBE'd — richer than the design doc's shorthand)

Design-review finding #8 and `manifests/woocommerce.json`'s convention both refer to `nf3_*` in shorthand. The real NF 3.14.11 schema is **11 tables**, structurally simpler than "intra-table FKs" suggests for the definition side, and genuinely polymorphic only on the submission side:

| Table | Columns | Role |
|---|---|---|
| `nf3_forms` | `id, title, key, created_at, updated_at, views, subs, form_title, default_label_pos, show_title, clear_complete, hide_complete, logged_in, seq_num` | Form definition (authored). `id` is the FK target below. |
| `nf3_fields` | `id, label, key, type, parent_id, ..., field_key, order, required, default_value, label_pos, personally_identifiable` | A field. **`parent_id` → `nf3_forms.id`, always** (not polymorphic — confirmed by reading `NF_Admin_Processes_ImportForm::insert_fields()`, which hard-sets `parent_id = $this->form['ID']`). |
| `nf3_actions` | `id, title, key, type, active, parent_id, created_at, updated_at, label` | A form action (save/email/success-message/...). **`parent_id` → `nf3_forms.id`, always**, same as fields. |
| `nf3_form_meta` / `nf3_field_meta` / `nf3_action_meta` | `id, parent_id, key, value, meta_key, meta_value` (dual key/value columns — a back-compat artifact; current code writes both) | Overflow settings for the row identified by `parent_id` in the corresponding parent table — one row per setting key (not one serialized blob). |
| `nf3_objects` | `id, type, title, created_at, updated_at, object_title` | Generic object table, `type`-discriminated. **Empty after importing a 23-field form** — confirmed empirically that form/field/action definitions never touch it. |
| `nf3_object_meta` | `id, parent_id, key, value, meta_key, meta_value` | Meta for `nf3_objects` rows. Also empty pre-submission. |
| `nf3_relationships` | `id, child_id, child_type, parent_id, parent_type, created_at, updated_at` | **Polymorphic** (string-typed `child_type`/`parent_type` discriminate which table `child_id`/`parent_id` actually indexes). **Empty after form import** — never touched by `insert_form()`/`insert_fields()`/`insert_actions()`. Confirmed reserved for submissions (see next section). |
| `nf3_chunks` | `id, name, value` | No FK column observed. |
| `nf3_upgrades` | `id, cache, stage, maintenance` | Migration-stage bookkeeping (env-local, like `yoast_migrations`) — not per-site content. |

So the "typed snapshot" frontier splits into two genuinely different shapes, not one:
1. **Form/field/action definitions** (`nf3_forms`/`nf3_fields`/`nf3_actions` + their `_meta` twins) — **authored**, single-target integer FKs (`parent_id` always points into one specific, statically-known table per source table — simpler than menus' polymorphic `_menu_item_type`/`_menu_item_object_id`).
2. **Submissions** (`nf3_objects`/`nf3_object_meta`/`nf3_relationships`) — expected **runtime**, polymorphic FKs, confirmed empirically below.

## The site

Content built on r1a1: CF7 "Contact Us" (post #18 — recreated once mid-round under a proper admin actor context, see the core-loop section below; shortcode `[contact-form-7 id="3c15ca6" title="Contact Us"]`), Ninja Forms "Job Application" (form id 1, 23 fields / 3 actions). Pages: About (#4, plain content), Contact (#2, CF7 embedded via a `core/shortcode` block), Careers (#3, Ninja Forms embedded via its native `ninja-forms/form` block, `{"formID":1,"formTitle":"Job Application"}`). A "Main" menu (Home custom link + About/Contact/Careers post-type items) assigned to twentytwentyone's `primary` location. Three ordinary posts across two categories (Announcements, Careers).

## An incidental infrastructure finding, honestly reported

The shared Docker daemon (OrbStack) went fully unresponsive for roughly two hours mid-round (`docker ps`/`compose up -d`/`exec` all hung indefinitely — confirmed not specific to r1a's containers) — evidence gathered and escalated to team-lead via SendMessage; team-lead force-restarted it, volumes intact. The consolidated seed script had died partway through `reset_env_state` (after `site empty --yes` wiped posts/pages, before the nf3_\* truncation loop completed), leaving a real, informative half-reset state: Ninja Forms rows survived, CF7/pages/posts didn't. Re-running the (idempotent, safe-to-rerun) script from the top after recovery produced a fully consistent site. No destructive action was taken; no other agents' envs were touched.

## Anonymous submissions — where the data actually lands (empirically verified, corrects an assumption)

**Contact Form 7**: submitted 3 times via the non-JS POST fallback (`your-name`/`your-email`/`your-subject`/`your-message` + the form's hidden fields, `_wpcf7=1` — CF7's rendered HTML uses the numeric post ID here, resolved fresh server-side from the shortcode's hash at every render, see below). All three returned HTTP 200. Confirmed via container logs that CF7 genuinely attempted delivery (`sh: /usr/sbin/sendmail: not found` — this sandbox has no MTA, an environment fact, not a CF7 defect). **Zero database footprint**: `wp post list --post_type=any --format=count` read the same both before and after (6: 3 pages + 3 posts) — no Flamingo/cf7/feedback-named tables exist either. CF7 is mail-only by default, exactly as its documentation claims; there is no runtime data to isolate on round-trip because none is created.

**Ninja Forms — a significant correction to this report's own working assumption.** The natural reading of design-review finding #8 ("Ninja Forms `nf3_*` (intra-table FKs)") is that submissions, like form definitions, live in the custom tables. **They don't.** Submitting the Job Application form anonymously via its real front-end path (`admin-ajax.php?action=nf_ajax_submit`, the exact `wp_ajax_nopriv_nf_ajax_submit` hook the JS builder uses — nonce obtained via the documented "just-in-time nonce" recovery the class itself implements: submit once with no nonce, read `errors.nonce.{new_nonce,nonce_ts}` back, resubmit) produced **six real submissions, landing as ordinary WordPress posts**: `post_type = nf_sub`, IDs 12–17, each with normal `wp_postmeta` rows — `_field_<field_id>` per answered field, `_form_id` (=1, a plain int, no ref declared anywhere), `_seq_num`. This is fully inside Duo's existing, already-working post/postmeta capture machinery; the only reason it doesn't appear today is that `nf_sub` is (correctly) never added to `site.duo.json`'s `policy.post_types` scope. Confirmed via direct `wp post meta list` — no serialization, no custom-table involvement whatsoever.

`nf3_objects`/`nf3_object_meta`/`nf3_relationships` (the generic, polymorphic-shaped tables that most resemble the "intra-table FK" danger design finding #8 describes) were empirically confirmed **empty of any submission data** through this entire exercise — the only two rows ever observed there, `type="log"`, were incidental byproducts of this session's own debugging (malformed test payloads that hit a genuine Ninja Forms PHP-8-compatibility fatal — `array_merge(): Argument #1 must be of type array, null given` in `AJAX/Controllers/Submission.php:500`, triggered by submitting a `fields` map missing entries the real front-end JS always includes for every field on the form, not just answered ones). Once the payload included all 23 field slots (empty string for unanswered ones) plus `extra:{}`/`settings:{}`, submissions succeeded cleanly with real `sub_id`s. **Caveat, stated plainly**: this round exercised the core submit path only; it does not rule out `nf3_objects` being used by save-and-resume (PayPal/Stripe return flows), repeater-field sub-structures, or other addons this site never triggered — "confirmed empty for what was exercised," not "proven never used."

**A related, real caching hazard, found the hard way.** `nf3_upgrades` is not purely migration-stage bookkeeping (this report's own draft assumption, now corrected) — it doubles as Ninja Forms' own settings **cache**, one row per form id (`WPN_Helper::update_nf_cache()`/`get_nf_cache()`, keyed by the exact same id-space as `nf3_forms.id`), read by both the general model API (`Ninja_Forms()->form($id)->get_fields()`) and the submission handler. This round's own `reset_env_state` originally truncated `nf3_forms` (resetting its `AUTO_INCREMENT`) without also clearing `nf3_upgrades` — the next form import reused id 1, and every read of "form 1" silently served the **previous, unrelated form's stale cached settings** (a freshly-imported 23-field "Job Application" read back as the old 4-field "Contact Me") until the stale row was cleared and the cache rebuilt. Fixed in the seed script (now truncates `nf3_upgrades` too). This has nothing to do with Duo specifically — any real-world scenario where `nf3_forms` rows get deleted and recreated hits the same staleness — but it lands directly on the acceptance criteria below: **any future typed-snapshot capability for Ninja Forms must rebuild this cache per form id post-apply**, the same "manifest declares rebuilders" pattern already shipped for Yoast (`wp yoast index`) and Elementor (`wp elementor flush-css`).

## The core loop: loud gate → pending → classify → clean capture

`site.duo.json` scoped `post_types: [post, page, attachment, wpcf7_contact_form]` — `nf_sub` deliberately excluded (see above). First `wp duo capture` aborted loudly, naming exactly CF7's seven real postmeta keys: `_additional_settings`, `_form`, `_hash`, `_locale`, `_mail`, `_mail_2`, `_messages`. `wp duo pending --format=json` surfaced those plus seven options never seen before in this codebase's spikes. Every key classified deliberately, journal evidence noted, human judgment overriding the journal where the automatic proposal was absent or imprecise:

| Key | Class | Rationale |
|---|---|---|
| `post_meta:_additional_settings`, `_form`, `_hash`, `_locale`, `_mail`, `_mail_2`, `_messages` | **authored** | CF7's own `save()` postmeta, verified to contain zero ids (see manifest notes above). The journal proposed `null` for all seven even after fixing the seed script to run as `admin` (`wp_set_current_user()`) — correctly: DESIGN.md 3.1.5 deliberately excludes CLI from ever proposing authored regardless of capability, and this engine implements that precisely (confirmed: `caps={manage_options:1}` but `surfaces={cli}` still yields `proposal:null`). Classified authored on human judgment, which `wp duo pending`'s own docs explicitly sanction — the proposal is advisory, never authority. |
| `options:category_children` | **derived** | WP core's own parent→children category-id cache, rebuilt automatically; never authored. |
| `options:fresh_site` | **env** | A one-time "is this a brand-new install" flag WP core sets, analogous to the `first_activation` timestamps already classified `env` elsewhere in this repo's manifests. |
| `options:nf_doing_import_form`, `nf_import_form` | **runtime** | Ninja Forms' own transient batch-import scratch state (confirmed by reading `NF_Admin_Processes_ImportForm`/`NF_Abstracts_BatchProcess`) — deleted at completion via `cleanup()`, but the journal remembers the write happened. Defensive classification in case a future interrupted import leaves them present at capture time. |
| `options:ninja_forms_needs_updates` | **runtime** | Journal proposed this correctly (26 anon/ajax writes) — an internal upgrade-gate check flag, re-derived on every request. |
| `options:secret_key` | **env, overriding the journal's "runtime" proposal** | Verified empirically: this sandbox's `wp-config.php` never defines `SECRET_KEY`/`AUTH_KEY` (only `WP_ENVIRONMENT_TYPE`/`DUO_JOURNAL`), so WordPress core's `wp_salt()` lazily generates and persists a **real cryptographic salt** here on first use (`wp_generate_password(64, true, true)`) — a high-entropy value, correctly flagged `secret:"suspicious"` by the heuristic tier. The journal's "runtime" guess is defensible (excluded from capture either way) but imprecise: DESIGN.md 3.1's own worked examples name "salts" as the canonical env-bound case, not runtime. Classified `env` as the semantically correct bucket, not rubber-stamped from the proposal — the exact "journal is a proposal generator, never an authority" posture the design calls for. |
| `options:wp_calendar_block_has_published_posts` | **derived** | WP core's Calendar-block cache flag (do-any-posts-exist boolean). Carried a `ref_hint` pointing at post id 1 ("Contact Us") — confirmed as pure coincidence (`bare_id`-style hints explicitly warn "small ids coincide," and this is that warning firing correctly on a false positive, not a bug). |

A methodological note worth stating plainly: the very first pending scan showed `caps:{anon:1}` on every CF7 postmeta key, because the CF7 seed script (unlike the Ninja Forms one) never called `wp_set_current_user()` — a real gap in *this exploration's own seeding fidelity*, not the engine. Fixed mid-round (deleted and recreated the form under an admin actor context) — the corrected evidence is what the table above reflects. Left in the report because it is itself a useful, reproducible lesson: journal evidence is only as trustworthy as the actor context a seed script actually simulates.

Capture succeeded cleanly (7 posts, 3 terms, 1 menu, 1 options file) and capture-twice produced zero diff (determinism confirmed).

## Manifests shipped

**[`manifests/contact-form-7.json`](../../manifests/contact-form-7.json)** — real, substantive, all eight postmeta keys `authored`, with `_old_cf7_unit_id` declared as the alternate lookup fact for CF7's legacy positional shortcode. The generic shortcode locator canonicalizes `[contact-form <old_id> "..."]` to the form token in post bodies and structured string leaves, then restores the target form's own alternate on apply; raw source numeric ids are never retained in canonical content. The modern hash shortcode remains ref-free. Exported via `wp duo policy-to-manifest`, reformatted from the engine's actual 4-space `JSON_PRETTY_PRINT` output to this repo's established 2-space convention for consistency with every other shipped manifest (a minor, worth-noting spec/implementation mismatch: `spec/repo-format.md` states "2-space pretty-print" but `Canon::encode()` calls PHP's `json_encode(..., JSON_PRETTY_PRINT | ...)`, which is unconditionally 4-space — every existing manifest in this repo must have been hand-reformatted after export, since the tool itself has never produced 2-space output). Swap-and-verify performed exactly per the spike_f pattern: pinned the manifest, stripped the now-redundant inline policy rules, re-captured, diffed byte-identical against the pre-swap state.

**[`manifests/ninja-forms.json`](../../manifests/ninja-forms.json)** — real (not draft), but deliberately thin and explicit about it. Ships the three confirmed-safe runtime options, `nf_sub` classified `runtime` as a defensive backstop, and the `nf3_*` tables marked with the exact `authored_typed_snapshot_post_v1` intent-marker `manifests/woocommerce.json` already uses for `woocommerce_attribute_taxonomies` (same root gap, independently hit from a second plugin this round). Unlike `docs/frontier/polylang.md`'s decision to ship *nothing*, this manifest is genuinely safe to pin today: there is no `site.duo.json` scope-toggle that can accidentally activate custom-table capture (no `policy.tables` mechanism exists at all — `Capture.php` never iterates tables, full stop), so — unlike Polylang's taxonomy-scoping trap — there is no loaded gun here. The honest limitation is coverage, not corruption risk: pinning this manifest captures zero of a Ninja Forms form's actual content.

## Round-trip, lint, and render acceptance

- **Round-trip**: pushed r1a1 → cloned into r1a2 → `wp duo apply --adopt-by-slug=terms,posts` → canary `clean`, 1 term adopted (`Uncategorized`), 10 created + 1 updated. Re-capturing r1a2 and diffing against r1a1's canonical state: **byte-identical**.
- **`wp duo lint` (hard gate)**: **zero findings** — including against the Careers page's `<!-- wp:ninja-forms/form {"formID":1,...} /-->` block. This is not a clean bill of health; it is the blind spot described below caught in the act.
- **CF7 render**: r1a2's Contact page resolves the shortcode correctly — `_wpcf7` value and unit-tag both carry r1a2's *own* numeric post id (18 on r1a1, 7 on r1a2), the shortcode text itself (`id="3c15ca6"`, the persisted hash's first 7 characters) needed zero rewriting, and no r1a1 host string (`localhost:8814`) appears anywhere in r1a2's rendered pages.
- **Ninja Forms render — the sharpest finding of this round**: r1a2 independently ended up with **zero** `nf3_forms` rows of its own (its one-time activation-seeded "Contact Me" was never recreated after an earlier reset — see below), so the Careers page's `formID:1` reference points at nothing. Calling the exact code path Ninja Forms' own front-end uses to resolve that id (`Ninja_Forms()->form(1)->get_fields()` / `get_setting('title')`) throws an **uncaught PHP fatal error** (`TypeError: Cannot access offset of type array in isset or empty`, `Model.php:210`) — not a graceful empty form, a crash. Both the byte-diff round-trip check *and* the hard lint gate report this environment as clean.
- **Runtime isolation**: r1a1 kept its 6 `nf_sub` submissions throughout; r1a2 started and stayed at 0 until its own fresh anonymous submission attempt (which itself cannot succeed meaningfully, for the same reason the render is broken — there is no valid form to submit to). CF7 or apply never touched `nf_sub` at all (correctly out of policy scope). A fresh anonymous CF7 submission on r1a2 post-apply left r1a1 untouched (trivially, but confirmed): CF7 has zero database footprint on either side — every submission attempt in this round genuinely tried to send mail (`sh: /usr/sbin/sendmail: not found` in both containers' logs, this sandbox's own environment fact, not a CF7 defect) and left no other trace.

## Divergent-edit merge — a real form-definition entity, both a clean merge and a genuine conflict

Branched the site repo (`edit-r1a1`/`edit-r1a2`) from a common base. On r1a1, edited the CF7 form's mail recipient (`sales-updated@example.test`); on r1a2, edited a different field, the success message (`_messages.mail_sent_ok`). Captured and pushed both branches; `git merge` produced **two real conflicts**, not zero: `modified_gmt` (both branches independently re-saved the same entity, so this one bookkeeping line collides on every concurrent edit to any entity — trivial to resolve, pick either timestamp) and CF7's own flattened `post_content` mirror (CF7 concatenates every property's value into `post_content` "for feeds/search," so touching *any* property reflows this text region regardless of which field changed — a real, if minor, source of spurious textual conflicts that has nothing to do with Duo and everything to do with CF7's own redundant-mirror design). Both resolved by inspection in under a minute. Critically, **the front-matter JSON itself — the actual data — merged with zero conflicts**: both edits landed on their own lines and combined cleanly. Applied the merged result to both r1a1 and r1a2; both now show `recipient: sales-updated@example.test` **and** `mail_sent_ok: "Thanks! We will be in touch within one business day."` — full convergence, the exact Spike B pattern proven for the first time in this repo against a plugin-defined post type rather than a core one.

## Conformance

**`contact-form-7`: shipped and green.** Added `sandbox/conformance/seeds/contact-form-7.sh` + `checks/contact-form-7.sh`, registered in `sandbox/conformance/manifests.json`, added to the `.github/workflows/conformance.yml` matrix. `bash sandbox/conformance/run.sh contact-form-7` passes clean end-to-end: hard lint gate 0 findings, capture-twice deterministic, apply canary clean, byte-identical round-trip, render check confirms conf2 renders its *own* numeric CF7 id (distinct from conf1's) with the shortcode's hash needing no rewriting, and a fresh anonymous submission on conf2 leaves conf1's post count unchanged.

**`ninja-forms`: no conformance entry, and that is the honest answer, not an oversight.** `docs/frontier/polylang.md` refused to ship *any* manifest because enabling Polylang's taxonomies was an active corruption trap. This round's situation is different in kind (`manifests/ninja-forms.json` is genuinely safe to pin — no scope toggle can activate custom-table capture, so there is no trap) but the same in spirit: a conformance gate is supposed to certify "capture → apply → re-capture reproduces this plugin's content faithfully," and this manifest deliberately captures none of Ninja Forms' defining content. A green run would prove only "the three runtime options don't regress," which is real but not what "conformance-grade" ought to mean. Recommending against claiming that grade here is the honest output of this exploration, not a gap in effort.

## Gap taxonomy

**(a) Engine gaps — escalated as tasks #75 and #76, `SendMessage`'d to main:**

1. **No typed-snapshot capture/apply for any custom table** (task #75). `Policy::table_rule()` is consulted only by `Journal.php`'s provenance report — never by `Capture.php` — confirmed by reading the source, not inferred. Ninja Forms' form/field/action definitions (`nf3_forms`/`nf3_fields`/`nf3_actions` + their `_meta` twins) are consequently 100% uncapturable: a site builder's actual form content cannot round-trip, merge, or apply at all. The real schema is *simpler* on the FK front than design-review finding #8's phrasing implied (plain single-target `parent_id` FKs, never polymorphic) but the required capability is the same one finding #8 and `manifests/woocommerce.json`'s `authored_typed_snapshot_post_v1` marker have both been pointing at. Needs, concretely (derived from reading `Ledger.php`/`Tokens.php`/`Policy.php`): new `Ledger` id_kind constants (the `duo_map` table schema already tolerates arbitrary id_kind strings — only the PHP call sites are hardcoded to post/term/term_taxonomy), new `Tokens::KIND_MAP` entries, a UUID-minting side channel for rows with no postmeta-equivalent column, manifest-declared column-level FK ref rules with insert ordering, and — confirmed necessary the hard way — a rebuilder: Ninja Forms caches per-form settings in `nf3_upgrades` keyed by the same id-space as `nf3_forms.id`, and this round reproduced empirically that a reused id silently serves the *previous* form's stale settings until that cache is rebuilt. Full acceptance criteria on the task.
2. **`wp duo lint`'s `looks_like_id_attr()`/`looks_like_id_key()` miss the all-caps "ID" naming convention** (task #76). Case-sensitive regex `/(Id|Ids)$/` matches WordPress core's own `mediaId`/`termIds` style but not Ninja Forms' real `formID` attribute (`preg_match('/(Id|Ids)$/', 'formID')` is false, verified directly) — a general blind spot, not Ninja-Forms-specific, that silently lets exactly the danger class this check exists for sail through. Empirically severe, not cosmetic: the dangling reference it misses causes an uncaught PHP fatal error on the target environment. Third independent confirmation (after `docs/frontier/fse.md` and `docs/frontier/polylang.md`) that a clean byte-diff round-trip is not sufficient proof of correctness on its own.

**(b) Manifest gaps — closed in-round:** `manifests/contact-form-7.json` (new, full coverage) and `manifests/ninja-forms.json` (new, honestly partial — see above) both shipped.

**(c) Harness gaps:** none found in the conformance harness itself; `sandbox/conformance/run.sh`'s existing pattern (seed → capture → lint → round-trip → render check) worked without modification for the new `contact-form-7` entry. The `spec/repo-format.md` 2-space-vs-`Canon::encode()`'s-actual-4-space mismatch (noted above) is a real, minor documentation/implementation drift worth a follow-up but not blocking anything.

## Files changed

- `sandbox/docker-compose.yml` — `r1a` profile (db/wp/cli-r1a1 :8814, db/wp/cli-r1a2 :8815, journal on), inserted after the `spikeg` block.
- `sandbox/tests/grind/grind_r1a_forms.sh` (new) — the full, re-runnable round: boot/install, content-reset (including the corrected `nf3_upgrades` truncation), CF7 + Ninja Forms real seeding, pages/menu/posts.
- `manifests/contact-form-7.json` (new), `manifests/ninja-forms.json` (new).
- `sandbox/conformance/seeds/contact-form-7.sh` (new), `sandbox/conformance/checks/contact-form-7.sh` (new), `sandbox/conformance/manifests.json` (added `contact-form-7` entry), `.github/workflows/conformance.yml` (added to matrix).
- `Makefile` — `grind-r1a` target (additive).
- `docs/grind/r1a-forms.md` (this file, new).
- Tasks #75/#76 created (engine gaps); no `agent/src/` edits.

## Run tails

```
$ bash sandbox/conformance/run.sh contact-form-7
...
ok: both envs installed (contact-form-7: contact-form-7)
ok: capture-twice diff is empty
lint: clean, 0 findings
ok: apply succeeded, side-effect canary clean
ok: canonical state identical across environments
ok: conf2 renders its own numeric CF7 id (7, conf1's was ...) — the captured
    shortcode's hash resolved correctly without any id rewriting
ok: anonymous submission on conf2 succeeded and left conf1 untouched
✔ CONFORMANCE PASSED (contact-form-7)
```

```
$ wp duo capture --repo=/siterepo   # before classification
Error: duo: unclassified meta keys on in-scope entities (loud-and-blocking gate):
  - post_meta:_additional_settings
  - post_meta:_form
  - post_meta:_hash
  - post_meta:_locale
  - post_meta:_mail
  - post_meta:_mail_2
  - post_meta:_messages
```

```
$ wp eval "Ninja_Forms()->form(1)->get_fields();"   # r1a2, formID:1 doesn't exist there
PHP Fatal error: Uncaught TypeError: Cannot access offset of type array in
isset or empty in .../ninja-forms/includes/Abstracts/Model.php:210
```

## Verdict

Contact Form 7 is fully branchable today: real manifest, clean core loop, deterministic capture, clean round-trip, correct render on a second environment, a genuine divergent-edit merge with real (if trivial) conflicts resolved to full convergence, and now a green CI conformance gate. Ninja Forms is exactly as far from branchable as design-review finding #8 predicted for its *form definitions* — confirmed with a precise, empirically-grounded acceptance-criteria writeup rather than the finding's original one-line shorthand — but is *closer* to branchable than that finding implied for everything else: submissions are ordinary posts (already-working machinery, zero new capability needed, just correctly excluded from scope), and the FK shape that remains uncaptured is a plain single-target `parent_id`, not the polymorphic danger the finding's phrasing suggested. The round's sharpest deliverable is not the coverage gap itself but the proof that today's two independent safety nets — the byte-diff round-trip and the hard lint gate — both report this exact failure as clean, tracing that to one specific, fixable regex, and escalating it precisely.
