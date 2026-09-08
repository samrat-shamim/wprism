# Native form-tag round-trip — next evidence slice

WPForms Lite remains experimental and unready. This is the work plan after
the settings checkpoint in PR #599, not a native-run record or a capability
promotion. No shipped manifest or executable changes are proposed here.

## Source contract

The locked Lite 2.0.1.1 artifact in `artifacts.lock.json` supplies the contract.
`src/Admin/Forms/Ajax/Tags.php:69-86,154-179,255-270` handles the real
`wpforms_admin_forms_overview_save_tags` AJAX request. It resolves existing
terms or creates sanitized labels, assigns the selected `wpforms_form_tag`
term IDs to each allowed form, and separately writes the labels to
`settings.form_tags` through the native form handler. Those labels are text,
not term references, including a numeric-looking label such as `701`.

The same file's `get_prepared_data()` checks the overview nonce and the
`edit_others_forms` capability; `get_allowed_forms()` further admits each
form with `edit_form_single`. The normal All Forms page initializes the Tags
UI (`src/Admin/Forms/Tags.php:43-86`). A CLI call to a private method, a copied
writer, or a direct SQL seed does not prove this HTTP authoring path.

The loader is part of that contract: `src/Loader.php:612-625` registers the
Tags AJAX handler only for the overview page or `wpforms_is_admin_ajax()`.
`includes/functions/checks.php:421-437` requires an admin-page referrer.
The fixture therefore sends the exact native overview `Referer` only after
the same authenticated session has successfully read that page. Missing,
foreign, unvisited and failed-overview requests refuse before transport.

## Offline mechanism checkpoint

`tests/offline/regress_form_tags.php` uses the shipped capsule policy,
PostCapture, TermCapture, BodyRefGrammar, RelationshipMaterializer, Db and the
real immutable repository compiler. Its synthetic inputs establish:

- separate source/target form, term and term-taxonomy IDs, including a foreign
  taxonomy row whose numeric coordinates collide with the source;
- UUID-bound relationships alongside unchanged UTF-8 and numeric-looking
  body labels;
- exact preservation of an unrelated tag's complete term/taxonomy rows,
  another owner's tag relationship and the selected form's foreign-taxonomy
  relationship when its stale owned tag assignment is replaced;
- zero-DML replay, unmapped-tag refusal, an injected INSERT failure after a
  successful DELETE, complete relationship rollback and successful retry;
- byte-identical recapture and independent compiler convergence of the one
  managed form and both managed tag entities.

These 23 assertions are mechanism evidence, not native AJAX, complete target
inventory, full Apply orchestration, deployment or native rollback evidence.
The deliberately preserved target-only tag is not silently claimed as part
of the three-entity managed compiler comparison.

## Required native producer and admission

1. Use one owned exact-source, exact-artifact pair with preinstalled Lite and
   native seeded forms. Read the actual overview page, retain its emitted
   nonce, and submit the legal Tags AJAX payload in an authenticated native
   session. Prove HTTP/JSON success, native capability context, exact submitted
   fields, complete diagnostic witnesses and session retirement.
2. Create and assign the exact source labels `Intake Ω` and `701`. Require
   native source and target term/TT IDs to differ and none to equal `701`;
   bind the submitted labels and exact post-save `settings.form_tags` string
   values, types and order. The native AJAX writer resolves submitted `value`
   as identity but stores submitted `label` separately; ordinary alphabetic
   tags alone do not exercise this discriminator. Seed target IDs independently,
   including an unrelated local tag with a separate native preservation witness.
   Do not inject a canonical UUID as a substitute for native creation.
3. Run ordinary Capture/Plan/Apply, then fresh native `get_the_terms()` and
   form-handler reads. Bind the complete physical term, TT and relationship
   rosters plus exact form bodies; compare both assigned identities and body
   labels. Repeat with a native changed assignment and require zero-write,
   zero-action retry after convergence.
4. Retain complete source, target and source-repeat compiler inputs through
   the shared private tree transport. If ordinary target Capture includes
   the independently proved local tag, use only its exact compiler signature
   through `RepositoryConvergence::assertSame()`'s explicit target-only
   argument. No taxonomy-wide exclusion, path glob, label normalizer or
   omitted target inventory may manufacture equality.
5. Mutation-test the actual host admission, execute the resulting native
   producer, retain complete private records outside disposable roots, then
   destroy the owned pair, databases, roots and lease before reporting PASS.

Native route/form/response and WPForms label semantics stay capsule-owned.
The existing shared transport, compiler, identity and transaction machinery
remain the implementation boundary; a second admin route may justify a small
capsule-local session/request helper, not a runtime HTTP framework.

## Implemented producer, native verification in progress

`tests/live/regress_location_apply.sh` now admits an explicit seeded-target
`WPFORMS_APPLY_TAGS=1` profile. It runs the native sequence above and adds a
fifth `tags` selection after the existing baseline/embed/widget/routing cases.
The settings profile can run in the same pair, exercising both consumers of
the capsule-local `native-admin.php` session helper. Neither profile changes
the default content-only lane or creates a code baseline.

The actual author and Apply admission functions are exercised by
`tests/offline/regress_native_tag_evidence.php` (58 assertions), including
successfully compiled but wrong term/label inputs and complete target-only
inventory rejection without explicit preservation proof. The helper's
`regress_native_admin.php` (37 assertions) injects HTTP status/size failures,
multi-code transport errors, cookie failure and failed session retirement.
It also pins the native referrer and distinguishes an absent HTTP-API body
from an observed empty string; the string `0` is retained without coercion.
Every error-code data history and attempted request payload is retained
privately; a partial or error-shaped record cannot pass native admission.
These are deterministic fixture results, not a successful native round-trip.

The first combined run, producer `999ab097070c7520af830121f7c9a34a3525ad59`,
failed at the first tag POST with HTTP 400 before any physical change. Its
private sink `sandbox/tmp/wpforms-apply-native.YivuQ5` retains the complete
WordPress HTTP-API response (empty body, Content-Length 1), unchanged native
rows, an empty diagnostic witness and successful session retirement. It is
not a wire capture and does not prove a raw `0` response. The missing native
overview referrer prevented the locked loader from registering the handler.
The owned pair `wpftags01` and its disposable resources were destroyed.
The corrected helper is red/green tested against that producer, with a new
full aggregate and exact-source native run required before any PASS claim.

Media/QR uploads, template conversion, deletion authority, lifecycle,
submissions/mail and participant-declared combinations remain separate work.
The production-readiness record is unchanged; no family is marked covered.
