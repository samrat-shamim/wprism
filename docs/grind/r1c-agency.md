# Grind round R1-C — the agency stack, tested for INTERPLAY (task #48)

*Own env pair `r1c1` (:8818) / `r1c2` (:8819), docker-compose profile `r1c`, journal on (`DUO_JOURNAL`). Own site repo (`sandbox/siterepo/{origin-r1c.git,r1c1,r1c2}`). Driver script: [`sandbox/tests/grind_r1c_agency.sh`](../../sandbox/tests/grind_r1c_agency.sh) — re-runnable, resets r1c1/r1c2 content + ledger + journal each run; passes clean end to end (`make grind-r1c`). No `agent/src/` edits — every mitigation below is manifest/policy-level. `manifests/{acf,elementor}.json` untouched (read-only this round, per the round's ownership split).*

## Mission

Elementor + ACF active together, plus a custom CPT plugin (`duo-agency-cpt`: a `project` post type + `project_type` taxonomy) shipped through the site repo's own `code/` tree — spike G's dogfooding pattern, not a static read-only fixture mount. Every capability Duo has built gets exercised in ONE site: the ACF interpreter, `json_refs`, the escaped-URL tokenizer, `code/` deploy, and the hard lint gate. Plugins have each been proven in isolation (spike E, the elementor/FSE/polylang frontier reports, spike G); this round's job was to find out whether COMBINING them surfaces anything new. Short answer: mostly no — the three deliberate interplay points all round-trip cleanly with zero engine changes — but the round did surface one genuine, reproducible engine gap (below) plus an operational footgun in Elementor's own kit lifecycle worth documenting for future testers.

## Environment facts

- WordPress 7.0.2, PHP 8.3, MariaDB 11, theme `twentytwentyone` (classic — deliberately not FSE; that interplay is the FSE report's territory).
- **Advanced Custom Fields (ACF) 6.8.7** and **Elementor 4.2.1**, both `wp plugin install <slug> --activate` on both r1c1 and r1c2 independently (matching spike E / the elementor frontier report's precedent — Duo has no code-provisioning-on-apply path for wordpress.org plugins, only for the site's own vendored code).
- **`duo-agency-cpt`** (this round's fixture, [`sandbox/fixtures/duo-agency-cpt/duo-agency-cpt.php`](../../sandbox/fixtures/duo-agency-cpt/duo-agency-cpt.php)) is dogfooded through `code/`, per spike G's pattern: each env's `wp-content/plugins/duo-agency-cpt` binds from that env's OWN site-repo checkout's `code/wp-content/plugins/duo-agency-cpt`, and its activation state travels through canonical state + `wp duo deploy` exclusively — r1c2 never runs `wp plugin activate duo-agency-cpt` itself. Registers a `project` CPT (`has_archive`, rewrite slug `projects`) and a `project_type` taxonomy, plus three of its own classification-surface keys mirroring spike F's `duo-loop-demo` shapes but at a new grain (see "The core loop" below): `_duo_project_internal_notes` (authored post meta, REST-written), `duo_agency_client_api_key` (env-classified secret-shaped option), `_duo_project_views` (**runtime post meta**, not an option — spike F's `duo_loop_hits` was option-grain; this round's runtime write sits at post-meta grain instead, new coverage of the classification surface).

## Seeding method (fidelity notes, honest per the house style)

Elementor content was built via `\Elementor\Plugin::$instance->documents->get($id)->save(['elements' => [...]])` — the same pipeline the editor itself calls, matching the elementor frontier report's and conformance seed's own method (not a hand-approximated `_elementor_data` shape). `wp_set_current_user()` to an admin first, same reason the conformance seed does it: `Document::save()`'s `is_editable_by_current_user()` fails silently under wp-cli's default user id 0.

**One real authoring mistake, caught by Elementor's own validation, not Duo's**: the first attempt nested an `image` widget directly under a `section` (skipping the `column` level) for a full-width banner. Elementor's `Elements_Manager::create_element_instance()` doesn't defend against this — it throws `Call to a member function get_default_args() on array`, a PHP fatal, not a graceful rejection. Fixed by wrapping the banner in a `column` (`_column_size: 100`), matching the section → column → widget nesting every other Elementor fixture in this repo already follows. Not a Duo finding — Elementor's own structural validation gap — but worth stating plainly rather than glossing over, per the frontier reports' own convention of separating "my seeding mistake" from "the thing under test."

ACF's taxonomy field was configured `save_terms: true, load_terms: true` — the setting that makes an ACF taxonomy field ALSO drive the post's *native* term relationships (not just its own postmeta value), deliberately chosen to probe a fourth, unplanned interplay wrinkle: does the SAME classification (which `project_type` terms apply to a project) staying consistent across two independent representations — ACF's own interpreter-typed postmeta and the native `wp_term_relationships` captured through the ordinary per-post `terms:` front-matter path — survive capture/apply on both sides? Confirmed yes (see below).

## Interplay point 1 — ACF relationship field pointing AT Elementor-built pages

An ACF `relationship` field on `project` (`post_type: ['page']`, `return_format: id`) was set to reference the Elementor-built "Our Work" and "Start a Project" pages. **Round-trips cleanly, with zero special-casing.** The ACF interpreter's `rule_for_type()` classifies `relationship` as `ref: post[], cast: string` purely from the field-definition shape (`manifests/interpreters/acf.php`) — it has no concept of "what post type is the target," so it doesn't care that the target happens to be Elementor content rather than a plain page. Confirmed on r1c2 post-apply: `get_field('duo_project_related', ...)` resolves to r1c2's own local Elementor-page ids, never r1c1's.

## Interplay point 2 — an Elementor page linking to a project permalink

The "Our Work" page's button widgets link to `skyline-rebrand`/`nimbus-app-launch`'s permalinks as plain URL strings (`link.url`), entered the same way the elementor frontier report's own fixture enters an internal link — Elementor doesn't "know" it's internal either way, since no `id` accompanies a plain-URL link control. **Round-trips and renders cleanly.** `_elementor_data`'s `struct_capture()` pass tokenizes every string leaf not already consumed by a declared `json_refs` path (`Tokens::tokenize_leaves()`), which reaches `link.url` regardless of whether the URL happens to point at a CPT's permalink or an ordinary page's — the tokenizer has no post-type awareness either. Confirmed on r1c2: the rendered "Our Work" page's button `href`s resolve to `http://localhost:8819/projects/skyline-rebrand/` and `.../nimbus-app-launch/` — r1c2's own host and r1c2's own CPT permalink structure (registered by the code/-deployed plugin) — never r1c1's.

## Interplay point 3 — `project` CPT posts flowing through capture with ACF-interpreter-typed meta

The ACF interpreter (`Acf::post_meta_rule()`) resolves a field's type by reading the `_<key>` shadow-meta pointer to an `acf-field` definition post — it never inspects the OWNING post's `post_type` at all. Registering the field group's `location` against `post_type == project` (rather than `post`, as spike E used) required zero interpreter changes. Confirmed: `duo capture` blocked loudly on `duo-agency-cpt`'s own two unclassified post-meta keys while never touching ACF's three fields (image/relationship/taxonomy) — those were already correctly typed and captured, proving the schema-driven interpreter is genuinely orthogonal to which CPT hosts the field group.

## A fourth, unplanned interplay finding: `elementor_active_kit` silently drops when its target is out of scope

Pinning `manifests/elementor.json` classifies the option `elementor_active_kit` as `{"class": "authored", "ref": "post"}` — but the referenced entity (Elementor's `elementor_library` "Default Kit" post) only gets a UUID minted if `elementor_library` is *also* in the site's own `policy.post_types` (manifests can't declare scope — the same structural fact the FSE report already found). Reproduced deliberately in this round's very first capture, before `elementor_library` was scoped:

```
Warning: unmapped post id 1 left as-is (broken or out-of-scope reference)
Warning: option elementor_active_kit: unmanaged post id 1 — key skipped
Success: captured 0 posts, 1 terms, 0 menus, 1 options file(s), 0 media blob(s) -> /siterepo/state
```

`duo capture` **exits 0.** `elementor_active_kit` is simply absent from `state/options/core.json` — no error, no `[BLOCKED]`, nothing that would stop a script harvesting `$?` from moving on. Scoping `elementor_library` and re-capturing fixes it (`elementor_active_kit` becomes `{{post:<uuid>}}`), which is this round's in-tree mitigation — but the *engine* behavior that made the drop possible in the first place is unchanged and will repeat for the next manifest/site combination that forgets the same scope line. See "Escalated" below.

## The core loop: gate → pending → classify → clean capture → policy-to-manifest

`duo-agency-cpt`'s own three keys exercised the full loop exactly as spike F's `duo-loop-demo` did, at new grains (post-meta-authored + post-meta-runtime + option-secret, vs. spike F's post-meta-authored + option-runtime + option-secret):

1. **Gate**: `duo capture` aborted loudly, naming `_duo_project_internal_notes` and `_duo_project_views` — and did *not* abort on any of ACF's three fields (interplay point 3, confirmed by absence rather than by a passing assertion).
2. **Pending**: `wp duo pending --format=json` surfaced `_duo_project_internal_notes` proposed `authored` (journal-backed — an admin REST write via an application password, matching spike F's `duo-loop-demo` auth pattern), `_duo_project_views` proposed `runtime`, and `duo_agency_client_api_key` flagged `hard:stripe key` (the `sk_live_` pattern denylist).
3. **Classify secret guard, at the classify step specifically** (not just capture's): `wp duo classify --set='options:duo_agency_client_api_key=authored'` was refused —
   `Error: duo: refusing 'options:duo_agency_client_api_key=authored' — current value of options:duo_agency_client_api_key looks like a stripe key; pass --allow-secret to override` — confirming the guard fires at classify-time even before any capture would re-check it.
4. **Correct classification** (one semicolon-joined `--set=`, per the CLI's own documented wp-cli parsing traps): notes → `authored`, views → `runtime`, api key → `env` (not `authored` — the *correct* call for a real secret, distinct from the refused mistake above).
5. **Clean capture**: zero secret material anywhere under `state/` (grepped for `sk_live_`), `_duo_project_views` excluded, the note present verbatim.
6. **Graduation**: `wp duo policy-to-manifest --match='^_?duo_(project|agency)_?' --name=duo-agency-cpt` exported [`manifests/duo-agency-cpt.json`](../../manifests/duo-agency-cpt.json) (17 lines); pinning it and stripping the matching inline policy rules reproduced **byte-identical** captured state (spike F's exact swap-and-verify pattern) — the manifest is graduation-ready, not just plausible-looking.

## Round-trip r1c1 → r1c2

- **Hard lint gate** (`wp duo lint`): **zero findings** on the full agency site — ACF's interpreter-declared refs and Elementor's `json_refs`/text-tokenize leaves are all correctly "owned," so none of `bare_id`/`escaped_home`/`unregistered_block_attr`/`serialized_desc_ids` fire. Never softened or bypassed.
- **`code/` deploy**: activation of `duo-agency-cpt` traveled through canonical state alone (spike G's pattern, reproduced for a second plugin) — r1c2's `plan` showed the pending activation with `code_mismatch` empty (code already present via the bind mount), `duo deploy` activated it for real (REST route returned HTTP 401, not 404, proving `rest_api_init` fired), and a *second* deploy later in the round was a genuine no-op (`activated == [] and deactivated == [] and theme_switched == null`) — matching `Deploy::run()`'s documented idempotency.
- **Byte-identical canonical state**, twice (once after the activation-only step, once after the full agency-content commit).
- **Render checks, including negative host-leak**: r1c2's "Our Work" page and both project single pages were curled and grepped — own-host images (including the *same* attachment ACF's own hero field independently references — the shared-attachment-across-two-systems case), own-host project-permalink button links, and an explicit assertion that r1c1's host (`localhost:8818`) never appears anywhere in r1c2's rendered output.
- **Runtime isolation**, at two grains: `_duo_project_views` (**post-meta**, not options — new coverage vs. spike F's option-grain runtime check) increases independently on each environment under its own anonymous traffic and is never touched by `apply` in either direction (asserted via deltas, not an assumed absolute baseline — see the script's own comment on why); `duo_agency_client_api_key` (`env`-classified) never transits git or `apply` at all — r1c2 simply has no value for it.

## Divergent-edit merge

- **Clean case**: branch A (on r1c1) added a third `project_type` term to Skyline Rebrand's ACF taxonomy field; branch B (on r1c2) added a second related page to the SAME project's ACF relationship field. Different keys, same entity file — canonical JSON's one-key-per-line format meant git's line-based 3-way merge combined both edits with **zero conflict markers** (`Auto-merging ... Merge made by the 'ort' strategy.`). Both environments converged carrying both edits after apply.
- **Conflict case**: two branches (one per environment) both edited Nimbus App Launch's ACF `duo_project_hero` field to *different* new images. Git surfaced a genuine, scoped conflict (`CONFLICT (content): Merge conflict in state/posts/project/...--nimbus-app-launch.md`, exactly one `UU` entry) — resolved with an editorial `git checkout --theirs`, matching spike B's exact resolution style. Both environments converged on the resolved image, confirmed byte-identical by content hash (not by id — ids aren't portable across environments; see "methodology notes" below).

## Gap taxonomy

### Escalated (engine-level — task #73, see below)

**`elementor_active_kit`'s silent drop is a narrower instance of a general engine gap**: `Capture::option_ref_tokens()` treats an unresolvable ref-typed *option* as warn-and-drop, not loud-and-blocking — inconsistent with DESIGN.md's stated posture for authored-data loss on every OTHER surface (unclassified post/term meta hard-aborts; a secret hard-aborts; but a dangling option-level ref just prints a warning line among potentially many others and exits 0). Sent to main with acceptance criteria (task #73) rather than mitigated here, because the fix belongs in `agent/src/Capture/Capture.php`/`Tokens.php`, not in any site's policy — this round's site-level fix (scoping `elementor_library`) closes the hole for *this* site only, exactly the "manifest treadmill" pattern DESIGN.md's existential risk #2 warns about repeating per-site forever absent an engine-level backstop.

### Mitigated in-round (site policy, no engine/manifest changes)

- `elementor_library` added to `policy.post_types` (fixes the kit-ref drop for this site).
- `project`/`project_type` added to `policy.post_types`/`policy.taxonomies` (the FSE report's "manifests can't declare scope" finding, reproduced for a third plugin family).
- `duo-agency-cpt`'s three keys classified via the ordinary pending/classify loop, graduated to `manifests/duo-agency-cpt.json`.

### Harness / methodology notes (not Duo engine gaps)

- **Elementor's kit doesn't survive a wipe-and-reactivate cycle.** A `deactivate → wp site empty --yes → activate` cycle (this round's own reset-for-rerun-safety idiom, following spike F/G's precedent) left `elementor_active_kit` holding a stale pre-wipe id while the post it pointed at no longer existed — reactivating Elementor does not appear to re-run whatever creates the default kit. Worked around directly (`ensure_elementor_kit()` in the grind script: check the option resolves to a live `elementor_library` post, `wp_insert_post` + `update_option` if not) rather than depending on activation-hook timing. Anyone else's test harness that wipes content while Elementor stays "active" across the wipe will hit the identical footgun.
- **Both environments independently creating Elementor's "Default Kit" is a same-slug collision Duo already has a mechanism for**: the two envs' independently-created kit posts share the slug `default-kit` with different (or no) UUIDs, which blocks `apply` with the ordinary slug-collision guard until `--adopt-by-slug=posts` is added (this round's apply calls all carry `--adopt-by-slug=posts,terms`, not just `terms` as most other spikes needed). Generalizes beyond Elementor: **any manifest that classifies a plugin's activation-time-auto-created "singleton" post as authored+ref should probably say so in its own documentation** ("first apply on a fresh environment needs `--adopt-by-slug=posts`"), since forgetting it produces a plan-time `[BLOCKED]` rather than a corruption — annoying, not dangerous, but avoidable with one documentation line. Not escalated as its own task; noted here as a smaller, non-blocking observation worth folding into whatever documentation task eventually covers `--adopt-by-slug`'s usage guidance.
- **Docker daemon contention across concurrently-running grind rounds.** Mid-round, the shared Docker daemon became unresponsive for an extended period (`docker ps` itself timing out) while multiple sibling grind rounds (r1a, r1b) were simultaneously running their own multi-container WordPress+MySQL stacks; when it recovered, every currently-profiled container (`conf1`/`conf2`, `r1a1`/`r1a2`, `r1b1`/`r1b2`, `r1c1`/`r1c2`) had been killed with exit code 137 (SIGKILL) simultaneously — consistent with a host-level Docker Desktop restart or resource-exhaustion event, not anything any one script did. Recovered cleanly by re-running this round's own idempotent script; no data was at risk since nothing here writes outside `sandbox/siterepo/r1c*` and the ledger tables it owns. Noted for whoever eventually schedules concurrent grind rounds again — running 3-4 independent WordPress+MySQL stacks' worth of plugin installs at once appears to be genuinely resource-heavy on this host.

## Files changed

- `sandbox/fixtures/duo-agency-cpt/duo-agency-cpt.php` — new fixture plugin (`project` CPT, `project_type` taxonomy, 3 classification-surface keys).
- `sandbox/docker-compose.yml` — new `r1c` profile (`db`/`wp`/`cli` × `r1c1`/`r1c2`, journal on, spike-G-style nested bind mount for `duo-agency-cpt`), anchored after the last existing profile block.
- `Makefile` — additive `grind-r1c` target.
- `sandbox/tests/grind_r1c_agency.sh` — new, the full narrative this report describes; re-runnable.
- `manifests/duo-agency-cpt.json` — new, graduated via `policy-to-manifest`, verified byte-identical against the inline policy it replaces.
- `docs/grind/r1c-agency.md` — this report.
- **Not touched**: `manifests/acf.json`, `manifests/elementor.json`, `manifests/interpreters/acf.php`, `agent/src/**`, any sibling's env/profile/files.

## Run tail (final `make grind-r1c`, 40/40 assertions passed)

```
ok: r1c1 authored + pushed commit 1; r1c2 cloned the SAME commit — both envs' bind-mount sources exist, host-owned, with real content, before wp-r1c1/wp-r1c2 are created
ok: both envs installed (WP+ACF+Elementor active); duo-agency-cpt present in code/ but inactive on both; content/ledger/journal clean
ok: baseline captured: ACF+Elementor active, duo-agency-cpt correctly absent from active_plugins
ok: confirmed: the warning fired AND elementor_active_kit is silently ABSENT from captured state — not a loud abort, a quiet drop
ok: elementor_active_kit now correctly tokenized once its target post type is in scope
ok: r1c1 activated duo-agency-cpt for real; capture recorded it; r1c2 pulled the pending activation
ok: r1c2: activation traveled through canonical state alone — plan clean, deploy activated for real (REST route responds HTTP 401, not 404)
ok: canonical state identical across r1c1/r1c2 after activation-only deploy+apply
ok: seeded: ACF field group (image+relationship+taxonomy) on 'project'; 2 projects; 2 Elementor pages
ok: capture blocked loudly, naming both unclassified post-meta keys — ACF's own fields did NOT block capture (interplay point 3)
ok: pending reflects journal evidence: notes authored (journal-backed), views runtime, api_key hard-secret-flagged
ok: classify refused authored on the hard secret
ok: classified: _duo_project_internal_notes=authored, _duo_project_views=runtime, duo_agency_client_api_key=env
ok: no secret material anywhere under state/; runtime meta excluded; authored note present
ok: exported manifests/duo-agency-cpt.json (17 lines)
ok: policy == exported manifest: identical captured state either way — graduation-ready
ok: lint: zero findings
ok: deploy: genuine no-op (zero WP API calls) — activation already converged
ok: apply succeeded, canary clean
ok: canonical state identical: ACF refs remapped, Elementor json_refs/text-leaves remapped, project CPT meta interpreter-typed
ok: get_field(duo_project_hero) on r1c2 -> attachment #6, byte-identical to r1c1's upload
ok: ACF relationship field pointing AT Elementor-built pages round-trips correctly (interplay point 1)
ok: ACF taxonomy field and the native wp_term_relationships independently round-trip to the SAME answer on r1c2
ok: Our Work page: own-host images + own-host project-permalink button links; r1c1's host never leaked (interplay point 2)
ok: project CPT single page renders cleanly with no r1c1 host leak
ok: REST route responds on r1c2 (HTTP 401)
ok: runtime meta (post-meta grain) never crosses environments in either direction: r1c1=9, r1c2=4
ok: duo_agency_client_api_key stays exactly where it was set (r1c1 only)
ok: clean git merge: both branches' DIFFERENT ACF fields landed in the SAME merged file, zero conflict markers
ok: both environments converged: project alpha carries BOTH branches' edits after apply
ok: genuine git conflict, scoped to exactly the Nimbus project file's duo_project_hero line
ok: both environments converged on the resolved hero image (byte-identical content) after the conflict
ok: environments byte-identical after both merge scenarios

✔ GRIND R1-C (agency stack interplay) PASSED
```

## Verdict

The interplay surface between Elementor, ACF, and a code/-deployed CPT plugin is **clean** — all three deliberately-designed interplay points (ACF↔Elementor cross-references, Elementor↔CPT permalink links, ACF-on-CPT schema-driven typing) round-trip correctly today with zero engine or manifest changes, because each mechanism (the ACF interpreter, the block/text tokenizer, the ledger) is genuinely orthogonal to which plugin or post type it's operating on — none of them special-case "post," "page," or any specific plugin's post types. The one real engine-level finding this round surfaced (`elementor_active_kit`'s silent option-level drop) is narrow in scope but structurally general: **any** manifest declaring a ref-typed option whose target type a site forgets to scope will silently lose that option, forever, on every future capture, with only a warning easy to miss — exactly the class of bug DESIGN.md's loud-and-blocking posture exists to prevent, just not yet extended to this one code path. Escalated as task #73.
