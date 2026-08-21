#!/usr/bin/env bash
# Grind round R1-C (task #48) — the agency stack, tested for INTERPLAY:
# Elementor + ACF active together, plus a custom CPT plugin (duo-agency-cpt:
# a 'project' post type + 'project_type' taxonomy) shipped through the site
# repo's own code/ tree (spike G's dogfooding pattern), activated via
# canonical state + `wp duo deploy` rather than a static read-only fixture
# mount. Every capability Duo has built gets exercised in ONE site — the ACF
# interpreter, json_refs, the escaped-URL tokenizer, code/ deploy, and the
# lint gate — because plugins have each been proven in isolation; COMBINED
# is not. Own dedicated env pair (r1c1 :8818 / r1c2 :8819, profile "r1c",
# journal on); this script boots, installs, and seeds them itself — it
# never touches envs a/b/c/conf*/e*/fx*/g*/r1a*/r1b* or their site repos.
#
# Deliberate interplay points (each one's outcome is stated at its own
# assertion below, in the `say` line that introduces it):
#   1. An ACF relationship field on 'project' pointing AT Elementor-built
#      pages (does the ref-rewrite care what builder authored the target?).
#   2. An Elementor page linking to a project's permalink via a plain URL
#      (does the generic string-leaf tokenizer reach across the CPT
#      boundary the same way it does for ordinary pages?).
#   3. 'project' CPT posts flowing through capture with ACF-interpreter-
#      typed meta (does the schema-driven interpreter care about post type
#      at all, or only about the field-definition shape?).
#   4. (found along the way, not planned) elementor_active_kit's ref-typed
#      option landing on an out-of-scope post type — a manifest-completeness
#      footgun, reproduced deliberately below rather than silently avoided.
#      Originally surfaced as a SILENT warn-and-drop (this round's escalation
#      became task #73); the engine now aborts loudly by default, so step (1)
#      asserts the abort + the --force-unresolved-refs escape hatch instead.
#
# Re-run safety: envs r1c1/r1c2 are never torn down (docker compose down/
# clean is off-limits — other agents share this stack), so every run wipes
# WP content, the duo ledger tables, the journal, and the site-repo git
# state from scratch — mirroring spike_f/spike_g's exact approach. WordPress
# core/theme install is the only thing skipped on repeat runs (guarded by
# `core is-installed`). Elementor is explicitly deactivate/reactivate-cycled
# on every reset (not just left alone): `site empty --yes` wipes ITS
# activation-time-created default kit post like any other content, and
# nothing recreates it short of re-firing register_activation_hook.
set -euo pipefail
cd "$(dirname "$0")/../.."
COMPOSE="docker compose -f docker-compose.yml --profile r1c"
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
R1C1=http://localhost:8818
R1C2=http://localhost:8819

PROOF_LEGACY_COMPOSE="$COMPOSE"
[ -r "lib/proof_legacy_pair.sh" ] || fail "legacy proof pair library is missing: lib/proof_legacy_pair.sh"
# shellcheck source=../../lib/proof_legacy_pair.sh
source "lib/proof_legacy_pair.sh"

wp_env() { proof_legacy_pair_wp_env "$@"; }
wp_r1c1() { wp_env r1c1 "$@"; }
wp_r1c2() { wp_env r1c2 "$@"; }
GIT_1="git -C siterepo/r1c1 -c user.name=duo-r1c1 -c user.email=r1c1@example.test"
GIT_2="git -C siterepo/r1c2 -c user.name=duo-r1c2 -c user.email=r1c2@example.test"
wait_for() { proof_legacy_pair_wait_for "$@"; }
write_htaccess() { proof_legacy_pair_write_htaccess "$@"; }

install_env() { # install_env <r1c1|r1c2> <port> <title> — core/theme/ACF/Elementor, idempotent
  local env="$1" port="$2" title="$3"
  wait_for "$env"
  if ! wp_env "$env" core is-installed >/dev/null 2>&1; then
    wp_env "$env" core install \
      --url="http://localhost:$port" --title="$title" \
      --admin_user=admin --admin_password=admin \
      --admin_email=admin@example.test --skip-email
    wp_env "$env" theme install twentytwentyone --activate
    wp_env "$env" option update permalink_structure '/%postname%/'
    wp_env "$env" rewrite flush
    write_htaccess "$env"
    echo "env $env installed"
  else
    echo "env $env already installed"
  fi
  wp_env "$env" plugin is-installed advanced-custom-fields >/dev/null 2>&1 \
    || wp_env "$env" plugin install advanced-custom-fields --activate
  wp_env "$env" plugin is-installed elementor >/dev/null 2>&1 \
    || wp_env "$env" plugin install elementor --activate
}

ensure_elementor_kit() { # ensure_elementor_kit <r1c1|r1c2>
  # Elementor's default kit is created by register_activation_hook, but
  # `site empty --yes` wipes that post like any other content, and nothing
  # recreates it afterward — confirmed empirically: a deactivate/site-empty/
  # reactivate cycle left elementor_active_kit holding its STALE pre-wipe
  # id while the post it pointed at no longer existed (get_post() -> null).
  # This is a real, Elementor-internal dangling-reference footgun, distinct
  # from and upstream of the site-policy-scope footgun this round
  # deliberately reproduces in step (1) — worth a line in the report, not
  # worked around silently. Fix here is direct rather than depending on
  # activation-hook timing that didn't reliably refire kit creation: ensure
  # a live 'elementor_library' post exists and the option points at it.
  local env="$1"
  cat > "siterepo/${env}/.tmp-ensure-kit.php" <<'PHPEOF'
<?php
$id = (int) get_option('elementor_active_kit');
$post = $id > 0 ? get_post($id) : null;
if ($post === null || $post->post_type !== 'elementor_library') {
    $new_id = wp_insert_post([
        'post_type' => 'elementor_library',
        'post_title' => 'Default Kit',
        'post_status' => 'publish',
    ]);
    update_option('elementor_active_kit', $new_id);
    echo "recreated kit as $new_id (was $id)\n";
} else {
    echo "kit ok: $id\n";
}
PHPEOF
  wp_env "$env" eval-file "/siterepo/.tmp-ensure-kit.php"
  rm -f "siterepo/${env}/.tmp-ensure-kit.php"
}

reset_env_state() { # reset_env_state <r1c1|r1c2> — safe to call every run
  local env="$1"
  wp_env "$env" plugin deactivate duo-agency-cpt >/dev/null 2>&1 || true
  wp_env "$env" site empty --yes >/dev/null
  ensure_elementor_kit "$env"
  wp_env "$env" option delete duo_agency_client_api_key >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_duo_map" >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_duo_state" >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_duo_kv" >/dev/null 2>&1 || true
  wp_env "$env" duo journal-reset >/dev/null 2>&1 || true
}

# ============================================================ boot + repo scaffold

say "boot: db-r1c1/db-r1c2 only — code/ must be authored on the host before wp-r1c1/wp-r1c2 ever start (spike G's bind-mount bootstrap-order lesson: docker pins a bind-mount source at container-CREATE time)"
mkdir -p siterepo
$COMPOSE up -d db-r1c1 db-r1c2

say "fresh site repo, code/ authored BEFORE wp-r1c1/wp-r1c2 ever start"
rm -rf siterepo/origin-r1c.git siterepo/r1c1 siterepo/r1c2
git init --bare -b main siterepo/origin-r1c.git >/dev/null

mkdir -p siterepo/r1c1/code/wp-content/plugins/duo-agency-cpt
cp fixtures/duo-agency-cpt/duo-agency-cpt.php siterepo/r1c1/code/wp-content/plugins/duo-agency-cpt/duo-agency-cpt.php

# Deliberately minimal scope at first: 'project'/'project_type' and
# 'elementor_library' are added later, as their own scripted steps — see
# interplay point 4's footgun demo and the FSE report's "manifests cannot
# declare scope" finding, both reproduced on purpose rather than pre-solved.
cat > siterepo/r1c1/site.duo.json <<'EOF'
{
  "manifests": ["core", "acf", "elementor"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "acf-field-group", "acf-field"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template siterepo/r1c1/.gitignore
$GIT_1 init -q -b main
git -C siterepo/r1c1 remote add origin ../origin-r1c.git
$GIT_1 add -A
$GIT_1 commit -qm "init site repo: code/wp-content/plugins/duo-agency-cpt (inactive) + site.duo.json (core+acf+elementor, minimal scope)"
git -C siterepo/r1c1 push -qu origin main
git clone -q siterepo/origin-r1c.git siterepo/r1c2
pass "r1c1 authored + pushed commit 1; r1c2 cloned the SAME commit — both envs' bind-mount sources exist, host-owned, with real content, before wp-r1c1/wp-r1c2 are created"

say "start wp-r1c1/wp-r1c2 now that code/ is real — --force-recreate so a re-run's freshly re-authored code/ is what the bind mount attaches to"
$COMPOSE up -d --force-recreate wp-r1c1 wp-r1c2
pass "wp-r1c1/wp-r1c2 up with a fresh bind-mount attach against this run's code/"

install_env r1c1 8818 "Duo Agency R1C1"
install_env r1c2 8819 "Duo Agency R1C2"
reset_env_state r1c1
reset_env_state r1c2
pass "both envs installed (WP+ACF+Elementor active); duo-agency-cpt present in code/ but inactive on both; content/ledger/journal clean"

# ============================================================ baseline + the elementor_active_kit footgun

say "(1) INTERPLAY FINDING (behavior updated by task #73): elementor_active_kit is classified authored+ref:post by manifests/elementor.json, but 'elementor_library' is not yet in THIS site's policy.post_types — and the kit post is REAL, so this is a scope gap, not a dangling ref. This grind originally found a silent warn-and-drop here, escalated as task #73; capture must now ABORT loudly instead"

# DUO-3229 (fail closed on unscoped entity types) postdates this fixture and
# added its OWN, coarser, unconditional gate (Capture::build()'s scope_gaps()
# check, evaluated before EITHER capture attempt below can reach the option-
# ref-specific logic this section actually means to exercise — confirmed by
# reading build()'s own control flow, --force-unresolved-refs has no effect
# on it either, it only reaches the later option_ref_tokens() path): with
# 'elementor_library' entirely unscoped AND holding a real capturable kit
# post, THAT gate fires first, naming the type, not the option — starving
# both capture attempts below of the specific message they check for
# (caught live, DUO-3274's sweep). scope:post_type:X=runtime is exactly the
# escape hatch that gate's own message names; it does not add elementor_library
# to policy.post_types, so the option-ref gate below still correctly sees it
# as unscoped once the type-level gate is satisfied — confirmed live before
# touching either assertion below, both fire exactly as originally written.
wp_r1c1 duo classify --repo=/siterepo --set='scope:post_type:elementor_library=runtime' >/dev/null
if OUT1=$(wp_r1c1 duo capture --repo=/siterepo 2>&1); then
  echo "$OUT1"
  fail "capture succeeded despite elementor_active_kit pointing at a real, out-of-scope elementor_library post (expected task #73's loud-and-blocking gate)"
fi
echo "$OUT1"
grep -q "option 'elementor_active_kit' references post id" <<<"$OUT1" \
  || fail "abort message does not name elementor_active_kit and its raw id (got: $OUT1)"
grep -q "'elementor_library' is not in policy.post_types" <<<"$OUT1" \
  || fail "abort message does not name the unscoped post type and the exact policy key that fixes it (got: $OUT1)"
grep -q -- '--force-unresolved-refs' <<<"$OUT1" \
  || fail "abort message does not name the --force-unresolved-refs escape hatch (got: $OUT1)"
[ ! -e siterepo/r1c1/state/options/core.json ] \
  || fail "aborted capture still wrote state/ — the gate must block the whole capture, not just the one option"
pass "capture ABORTED loudly, naming the option, its raw id, the unscoped post type, the policy key to fix, and the escape hatch — and wrote nothing (task #73's posture upgrade over the quiet drop this grind first documented)"

say "(1) escape hatch: --force-unresolved-refs opts back into the pre-#73 warn-and-drop for this capture only — baseline capture proceeds under it"
OUT1F=$(wp_r1c1 duo capture --repo=/siterepo --force-unresolved-refs 2>&1)
echo "$OUT1F"
grep -qi 'elementor_active_kit.*unmanaged post id\|unmanaged post id.*elementor_active_kit' <<<"$OUT1F" \
  || fail "expected the old-style warning naming elementor_active_kit's unmanaged post id under --force-unresolved-refs (got: $OUT1F)"
# DUO-3211 (exact-reconciliation contract, landed after this assertion was
# first written): a policy-required authored option that gets dropped is no
# longer OMITTED from .records — every name in Policy::authored_options()
# gets an explicit record every capture, so a drop now means an explicit
# {"state":"absent"} entry, not a missing key. Confirmed live in isolation
# (a minimal authored+ref:post fixture mirroring elementor_active_kit
# exactly, DUO-3274's sweep) before touching this assertion: has() was
# TRUE and the record was {"state":"absent"}, not FALSE/missing as this
# check originally expected — the option genuinely used to vanish
# (task #73 era), and DUO-3211 is what changed "dropped" to mean "recorded
# absent" instead of "not recorded at all". Flipped, not deleted: the
# option's exclusion is still the thing under test, just represented the
# current way.
jq -e '.records.elementor_active_kit.state == "absent"' siterepo/r1c1/state/options/core.json >/dev/null 2>&1 \
  || fail "elementor_active_kit should carry an explicit state:absent record post-DUO-3211, not be silently omitted from .records (got: $(jq -c '.records.elementor_active_kit // "MISSING"' siterepo/r1c1/state/options/core.json))"
jq -e '.records.active_plugins.value | index("duo-agency-cpt/duo-agency-cpt.php") == null' siterepo/r1c1/state/options/core.json >/dev/null \
  || fail "r1c1's baseline active_plugins already includes duo-agency-cpt (got: $(jq -c .records.active_plugins.value siterepo/r1c1/state/options/core.json))"
pass "baseline captured under the escape hatch: warning fired, elementor_active_kit correctly recorded as an explicit state:absent (DUO-3211's exact-reconciliation contract — dropped means recorded absent, not silently omitted), duo-agency-cpt correctly absent from active_plugins"

say "(1) fix: scope 'elementor_library' into policy.post_types, re-capture"
# The scope:post_type:elementor_library=runtime classification added above
# (to satisfy DUO-3229's gate for the FIRST capture attempt) must come back
# out here: promoting the type to policy.post_types while that declaration
# still says "runtime" is a real, live-caught contradiction — the previous
# capture wrote classification=runtime into the committed state file, and
# Capture::run()'s own pre-build repository-authorization step (which
# compiles+authorizes on-disk state against CURRENT policy before build()
# runs) correctly refuses the mismatch: [repository_field_not_authored].
# Not a bug to route around — the fixture's job now is "become authored",
# so its own temporary runtime carve-out must be retracted in the same step.
jq '.policy.post_types += ["elementor_library"] | del(.policy.scope.post_type.elementor_library)' siterepo/r1c1/site.duo.json > siterepo/r1c1/.tmp-site.json
mv siterepo/r1c1/.tmp-site.json siterepo/r1c1/site.duo.json
wp_r1c1 duo capture --repo=/siterepo
jq -e '.records.elementor_active_kit.value | test("^\\{\\{post:")' siterepo/r1c1/state/options/core.json >/dev/null \
  || fail "elementor_active_kit is not a post token after scoping elementor_library (got: $(jq -c .records.elementor_active_kit.value siterepo/r1c1/state/options/core.json))"
pass "elementor_active_kit now correctly tokenized once its target post type is in scope"
$GIT_1 add -A
$GIT_1 commit -qm "capture: baseline + scope elementor_library (fixes the elementor_active_kit silent-drop finding)"
git -C siterepo/r1c1 push -q origin main

# ============================================================ activation travels through canonical (spike G pattern)

say "(2) on r1c1: activate duo-agency-cpt for REAL (activate_plugin(), hooks fire, CPT+taxonomy register) — an admin action, captured and pushed; r1c2 will receive it ONLY via canonical + deploy, never a direct 'wp plugin activate' of its own"
wp_r1c1 plugin activate duo-agency-cpt
wp_r1c1 plugin list --status=active --field=name | grep -qx duo-agency-cpt || fail "duo-agency-cpt did not actually activate on r1c1"
wp_r1c1 rewrite flush
wp_r1c1 duo capture --repo=/siterepo
jq -e '.records.active_plugins.value | any(. == "duo-agency-cpt/duo-agency-cpt.php")' siterepo/r1c1/state/options/core.json >/dev/null \
  || fail "r1c1's captured active_plugins does not include duo-agency-cpt after activating"
$GIT_1 add -A
$GIT_1 commit -qm "capture: activate duo-agency-cpt on r1c1"
git -C siterepo/r1c1 push -q origin main
git -C siterepo/r1c2 pull -q origin main
pass "r1c1 activated duo-agency-cpt for real; capture recorded it; r1c2 pulled the pending activation"

say "(2) on r1c2: plan shows pending activation as the ONLY code_mismatch finding (code already arrived via git, so no missing_in_code/code_drift — just inactive_in_environment); deploy activates for real"
# DUO-3216 (compose truthful promotion path, aa9b36a) folded "code present,
# not yet active" into code_mismatch itself as its own issue type
# (inactive_in_environment) — this section originally expected an empty
# code_mismatch for exactly this state, written before that reshaping and
# never updated (grind_r1c_agency.sh cites no DUO-3216 anywhere, unlike its
# r1b/r3a siblings — caught live via a full end-to-end run, DUO-3274's
# sweep). Two shapes exist on main for DUO-3216-era breakage (owner ruling):
# reorder to deploy-before-plan (grind_r1b_shop.sh's PR #14,
# grind_r3b_events.sh's DUO-3250/#37 — docs/code-half.md §3.4's
# deploy-before-apply contract), or flip the assertion to expect the
# inactive_in_environment finding when the section's own point IS that
# pending state. This section is the second kind: DUO-3250's own commit
# message explicitly checked grind_r1c_agency.sh and concluded "needs no
# change... its own scenario's plugin activation asymmetry already required
# deploy-first" — i.e. this plan-before-deploy ordering is deliberate, not
# an oversight, matching this section's own `say` text ("plan shows pending
# activation... deploy activates for real"). Reordering would defeat the
# point; the fix is the shape below — assert the SHAPE of the one expected
# finding instead of an empty array, which also catches a real regression
# (an unexpected SECOND finding, or the wrong plugin/issue) that an
# empty-array check never could.
PLAN_JSON=$(wp_r1c2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN_JSON" | jq -e '.code_mismatch | length == 1' >/dev/null \
  || fail "r1c2's plan does not show exactly one code_mismatch finding (got: $(echo "$PLAN_JSON" | jq -c .code_mismatch))"
echo "$PLAN_JSON" | jq -e '.code_mismatch[0].issue == "inactive_in_environment" and .code_mismatch[0].kind == "plugin" and .code_mismatch[0].plugin == "duo-agency-cpt/duo-agency-cpt.php"' >/dev/null \
  || fail "r1c2's single code_mismatch finding is not the expected inactive_in_environment/duo-agency-cpt shape (got: $(echo "$PLAN_JSON" | jq -c .code_mismatch))"
echo "$PLAN_JSON" | jq -e '.code_mismatch[0].message | contains("Run") and contains("deploy") and contains("before apply")' >/dev/null \
  || fail "inactive_in_environment message lost its deploy-before-apply guidance (got: $(echo "$PLAN_JSON" | jq -c .code_mismatch))"
DEPLOY_JSON=$(wp_r1c2 duo deploy --repo=/siterepo --format=json | tail -1)
echo "$DEPLOY_JSON" | jq -e '.activated | any(. == "duo-agency-cpt/duo-agency-cpt.php")' >/dev/null \
  || fail "deploy's summary does not list duo-agency-cpt as activated (got: $DEPLOY_JSON)"
wp_r1c2 plugin list --status=active --field=name | grep -qx duo-agency-cpt || fail "duo-agency-cpt is not active on r1c2 after deploy"
wp_r1c2 rewrite flush
REST_CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$R1C2/wp-json/duo-agency/v1/projects/1/notes")
[ "$REST_CODE" != "404" ] || fail "POST duo-agency/v1/projects/1/notes returned 404 on r1c2 — the route was never registered, activation hooks did not fire"
pass "r1c2: activation traveled through canonical state alone — plan clean, deploy activated for real (REST route responds HTTP $REST_CODE, not 404), rewrite rules flushed"

say "(2) apply on r1c2 converges remaining (still-empty) state; byte-identical canonical"
REV=$(git -C siterepo/r1c2 rev-parse HEAD)
APPLY_JSON=$(wp_r1c2 duo apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REV" --format=json | tail -1)
[ "$(echo "$APPLY_JSON" | jq -r '.canary')" = "clean" ] || fail "apply's canary was not clean on r1c2 (got: $APPLY_JSON)"
wp_r1c2 duo capture --repo=/siterepo --out=/siterepo/.tmp-r1c2state >/dev/null
diff -r siterepo/r1c1/state siterepo/r1c2/.tmp-r1c2state || fail "r1c2's re-captured baseline does not match r1c1's byte for byte"
rm -rf siterepo/r1c2/.tmp-r1c2state
pass "canonical state identical across r1c1/r1c2 after activation-only deploy+apply"

say "(2) extend policy scope for the CPT's own content: post_types += project, taxonomies += project_type"
jq '.policy.post_types += ["project"] | .policy.taxonomies += ["project_type"]' siterepo/r1c1/site.duo.json > siterepo/r1c1/.tmp-site.json
mv siterepo/r1c1/.tmp-site.json siterepo/r1c1/site.duo.json
$GIT_1 add -A
$GIT_1 commit -qm "policy: scope project + project_type (per the FSE report's finding — manifests can't declare scope, sites must)"
git -C siterepo/r1c1 push -q origin main
pass "scope extended on r1c1 (nothing to re-capture yet — no project/project_type entities exist)"

# ============================================================ build the agency site (all 3 planned interplay points)

say "(3) seed: ACF field group on 'project' (image + relationship-to-pages + taxonomy checkbox), project_type terms, two projects, two Elementor-built pages linking to project permalinks"
cat > siterepo/r1c1/.tmp-seed-agency.php <<'PHPEOF'
<?php
error_reporting(E_ALL & ~E_DEPRECATED);

// Document::save() (Elementor) refuses for no current user (id 0, wp-cli's
// default) — is_editable_by_current_user() fails silently otherwise.
$admins = get_users(['role' => 'administrator', 'number' => 1]);
if ($admins) {
    wp_set_current_user($admins[0]->ID);
}

function duo_r1c_img($path, $r, $g, $b) {
    $im = imagecreatetruecolor(96, 64);
    imagefilledrectangle($im, 0, 0, 95, 63, imagecolorallocate($im, $r, $g, $b));
    imagepng($im, $path);
    imagedestroy($im);
}
function duo_r1c_import($path, $title) {
    $id = media_handle_sideload(['name' => basename($path), 'tmp_name' => $path], 0, $title);
    if (is_wp_error($id)) {
        fwrite(STDERR, 'attachment import failed: ' . $id->get_error_message() . "\n");
        exit(1);
    }
    return (int) $id;
}
function duo_r1c_term($name, $tax) {
    $t = wp_insert_term($name, $tax);
    if (is_wp_error($t)) {
        if ($t->get_error_code() === 'term_exists') {
            return (int) $t->get_error_data('term_exists');
        }
        fwrite(STDERR, 'term insert failed: ' . $t->get_error_message() . "\n");
        exit(1);
    }
    return (int) $t['term_id'];
}

// ---- ACF schema: image + relationship (post_type=page) + taxonomy (checkbox) ----
acf_update_field_group([
    'key' => 'group_duo_agency',
    'title' => 'Project Details',
    'fields' => [],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'project']]],
    'menu_order' => 0, 'position' => 'normal', 'style' => 'default',
    'label_placement' => 'top', 'instruction_placement' => 'label', 'active' => true,
]);
$group_posts = get_posts([
    'post_type' => 'acf-field-group', 'name' => 'group_duo_agency',
    'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'any',
]);
$group_id = $group_posts ? (int) $group_posts[0] : 0;
if (!$group_id) { fwrite(STDERR, "field group not created\n"); exit(1); }

acf_update_field([
    'key' => 'field_duo_project_hero', 'label' => 'Hero Image', 'name' => 'duo_project_hero',
    'type' => 'image', 'parent' => $group_id, 'return_format' => 'id',
]);
acf_update_field([
    'key' => 'field_duo_project_related', 'label' => 'Related Pages', 'name' => 'duo_project_related',
    'type' => 'relationship', 'parent' => $group_id, 'post_type' => ['page'], 'return_format' => 'id',
]);
acf_update_field([
    'key' => 'field_duo_project_type_tax', 'label' => 'Project Type', 'name' => 'duo_project_type_tax',
    'type' => 'taxonomy', 'parent' => $group_id, 'taxonomy' => 'project_type',
    'field_type' => 'checkbox', 'return_format' => 'id', 'save_terms' => 1, 'load_terms' => 1,
]);

// ---- project_type terms ----
$web_id = duo_r1c_term('Web Design', 'project_type');
$brand_id = duo_r1c_term('Branding', 'project_type');
$mobile_id = duo_r1c_term('Mobile App', 'project_type');

// ---- hero images ----
duo_r1c_img('/tmp/duo-r1c-hero-alpha.png', 60, 120, 200);
duo_r1c_img('/tmp/duo-r1c-hero-bravo.png', 200, 120, 60);
$hero_alpha = duo_r1c_import('/tmp/duo-r1c-hero-alpha.png', 'Duo R1C Skyline Hero');
$hero_bravo = duo_r1c_import('/tmp/duo-r1c-hero-bravo.png', 'Duo R1C Nimbus Hero');

// ---- two project posts (bare — relationship field backfilled once the ----
// ---- Elementor pages below exist: interplay point 1 needs a real target)
$proj_alpha = wp_insert_post([
    'post_type' => 'project', 'post_status' => 'publish',
    'post_title' => 'Skyline Rebrand', 'post_name' => 'skyline-rebrand',
    'post_content' => "<!-- wp:paragraph -->\n<p>A full brand refresh for a downtown property group.</p>\n<!-- /wp:paragraph -->",
], true);
$proj_bravo = wp_insert_post([
    'post_type' => 'project', 'post_status' => 'publish',
    'post_title' => 'Nimbus App Launch', 'post_name' => 'nimbus-app-launch',
    'post_content' => "<!-- wp:paragraph -->\n<p>Native iOS and Android launch for a weather-data startup.</p>\n<!-- /wp:paragraph -->",
], true);
if (is_wp_error($proj_alpha) || is_wp_error($proj_bravo)) { fwrite(STDERR, "project insert failed\n"); exit(1); }

update_field('duo_project_hero', $hero_alpha, $proj_alpha);
update_field('duo_project_hero', $hero_bravo, $proj_bravo);
update_field('duo_project_type_tax', [$web_id, $brand_id], $proj_alpha);
update_field('duo_project_type_tax', [$mobile_id], $proj_bravo);

$alpha_url = get_permalink($proj_alpha);
$bravo_url = get_permalink($proj_bravo);

// ---- Elementor pages: "Our Work" (features both projects) + "Start a ----
// ---- Project" (CTA) — interplay point 2: plain-URL internal links to
// ---- project permalinks, entered the same way the elementor.md report's
// ---- own fixture did (Elementor doesn't "know" it's internal either way).
duo_r1c_img('/tmp/duo-r1c-work-banner.png', 90, 90, 90);
$work_banner = duo_r1c_import('/tmp/duo-r1c-work-banner.png', 'Duo R1C Our Work Banner');

$work_page_id = wp_insert_post([
    'post_type' => 'page', 'post_title' => 'Our Work', 'post_name' => 'our-work',
    'post_status' => 'publish', 'post_content' => '',
]);
update_post_meta($work_page_id, '_elementor_edit_mode', 'builder');
update_post_meta($work_page_id, '_elementor_template_type', 'wp-page');
$work_elements = [
    [
        'id' => 'r1csec01', 'elType' => 'section', 'settings' => [],
        'elements' => [
            [
                'id' => 'r1ccol00', 'elType' => 'column', 'settings' => ['_column_size' => 100],
                'elements' => [
                    ['id' => 'r1cimg00', 'elType' => 'widget', 'widgetType' => 'image', 'settings' =>
                        ['image' => ['id' => $work_banner, 'url' => wp_get_attachment_url($work_banner)]], 'elements' => []],
                ],
            ],
        ],
    ],
    [
        'id' => 'r1csec02', 'elType' => 'section', 'settings' => [],
        'elements' => [
            [
                'id' => 'r1ccol01', 'elType' => 'column', 'settings' => ['_column_size' => 50],
                'elements' => [
                    ['id' => 'r1cimg01', 'elType' => 'widget', 'widgetType' => 'image', 'settings' =>
                        ['image' => ['id' => $hero_alpha, 'url' => wp_get_attachment_url($hero_alpha)]], 'elements' => []],
                    ['id' => 'r1cbtn01', 'elType' => 'widget', 'widgetType' => 'button', 'settings' =>
                        ['text' => 'View Skyline Rebrand', 'link' => ['url' => $alpha_url]], 'elements' => []],
                ],
            ],
            [
                'id' => 'r1ccol02', 'elType' => 'column', 'settings' => ['_column_size' => 50],
                'elements' => [
                    ['id' => 'r1cimg02', 'elType' => 'widget', 'widgetType' => 'image', 'settings' =>
                        ['image' => ['id' => $hero_bravo, 'url' => wp_get_attachment_url($hero_bravo)]], 'elements' => []],
                    ['id' => 'r1cbtn02', 'elType' => 'widget', 'widgetType' => 'button', 'settings' =>
                        ['text' => 'View Nimbus App Launch', 'link' => ['url' => $bravo_url]], 'elements' => []],
                ],
            ],
        ],
    ],
];
$doc = \Elementor\Plugin::$instance->documents->get($work_page_id);
if ($doc->save(['elements' => $work_elements]) === false) { fwrite(STDERR, "Our Work Document::save() returned false\n"); exit(1); }

$cta_page_id = wp_insert_post([
    'post_type' => 'page', 'post_title' => 'Start a Project', 'post_name' => 'start-a-project',
    'post_status' => 'publish', 'post_content' => '',
]);
update_post_meta($cta_page_id, '_elementor_edit_mode', 'builder');
update_post_meta($cta_page_id, '_elementor_template_type', 'wp-page');
$cta_elements = [
    [
        'id' => 'r1csec03', 'elType' => 'section', 'settings' => [],
        'elements' => [
            [
                'id' => 'r1ccol03', 'elType' => 'column', 'settings' => ['_column_size' => 100],
                'elements' => [
                    ['id' => 'r1cbtn03', 'elType' => 'widget', 'widgetType' => 'button', 'settings' =>
                        ['text' => 'See Skyline Rebrand', 'link' => ['url' => $alpha_url]], 'elements' => []],
                ],
            ],
        ],
    ],
];
$doc2 = \Elementor\Plugin::$instance->documents->get($cta_page_id);
if ($doc2->save(['elements' => $cta_elements]) === false) { fwrite(STDERR, "Start a Project Document::save() returned false\n"); exit(1); }

// ---- backfill: interplay point 1 — ACF relationship field pointing AT ----
// ---- Elementor-built pages (alpha -> one page; bravo -> both, exercising
// ---- both single- and multi-element post[] arrays)
update_field('duo_project_related', [$work_page_id], $proj_alpha);
update_field('duo_project_related', [$work_page_id, $cta_page_id], $proj_bravo);

echo json_encode([
    'proj_alpha' => $proj_alpha, 'proj_bravo' => $proj_bravo,
    'work_page' => $work_page_id, 'cta_page' => $cta_page_id,
    'hero_alpha' => $hero_alpha, 'hero_bravo' => $hero_bravo,
    'web_id' => $web_id, 'brand_id' => $brand_id, 'mobile_id' => $mobile_id,
    'alpha_url' => $alpha_url, 'bravo_url' => $bravo_url,
]) . "\n";
PHPEOF
SEED_JSON=$(wp_r1c1 eval-file /siterepo/.tmp-seed-agency.php)
rm -f siterepo/r1c1/.tmp-seed-agency.php
echo "$SEED_JSON" | jq .
PROJ_ALPHA=$(echo "$SEED_JSON" | jq -r .proj_alpha)
PROJ_BRAVO=$(echo "$SEED_JSON" | jq -r .proj_bravo)
WORK_PAGE=$(echo "$SEED_JSON" | jq -r .work_page)
CTA_PAGE=$(echo "$SEED_JSON" | jq -r .cta_page)
WEB_ID=$(echo "$SEED_JSON" | jq -r .web_id)
BRAND_ID=$(echo "$SEED_JSON" | jq -r .brand_id)
MOBILE_ID=$(echo "$SEED_JSON" | jq -r .mobile_id)
ALPHA_URL=$(echo "$SEED_JSON" | jq -r .alpha_url)
pass "seeded: ACF field group (image+relationship+taxonomy) on 'project'; 2 projects; 2 Elementor pages; relationship fields backfilled against the real Elementor page ids"

say "(3) render both Elementor pages once — the 3 keys (_elementor_css/_elementor_element_cache/_elementor_migrations_state_<hash>) are created lazily on FIRST front-end render, not at save time (the Elementor frontier exploration's own finding)"
curl -fs "$R1C1/our-work/" >/dev/null || fail "r1c1 front-end render of 'Our Work' failed"
curl -fs "$R1C1/start-a-project/" >/dev/null || fail "r1c1 front-end render of 'Start a Project' failed"
pass "both Elementor pages rendered once on r1c1"

say "(3) admin-authored write via duo-agency-cpt's own REST route (post meta note) + a secret-shaped option (Stripe-key pattern)"
APP_PASS=$(wp_r1c1 user application-password create admin duo-grind-r1c --porcelain)
SECRET='sk_live_DUOR1CFAKEKEY1234567890TEST'
REST_BODY=$(printf '{"notes":"Retainer renews March; primary contact via Slack.","api_key":"%s"}' "$SECRET")
RESP=$(curl -fsu "admin:$APP_PASS" -X POST "$R1C1/wp-json/duo-agency/v1/projects/$PROJ_ALPHA/notes" \
  -H 'Content-Type: application/json' -d "$REST_BODY")
[ "$(jq -r '.project_id' <<<"$RESP")" = "$PROJ_ALPHA" ] || fail "REST notes write did not echo back project_id=$PROJ_ALPHA (got: $RESP)"
pass "admin REST write done (_duo_project_internal_notes on project #$PROJ_ALPHA, duo_agency_client_api_key=sk_live_...)"

say "(3) anonymous front-end traffic: nobody's authored content, the control case pending must classify opposite the note above, at the SAME grain (post meta) on the SAME post"
for _ in $(seq 1 5); do curl -fso /dev/null "$ALPHA_URL"; done
[ "$(wp_r1c1 post meta get "$PROJ_ALPHA" _duo_project_views)" = "5" ] || fail "_duo_project_views is not 5 after 5 anonymous GETs of project alpha's permalink"
pass "5 anonymous project-page GETs done (_duo_project_views should read 5)"

# ============================================================ the core loop: gate -> pending -> classify -> clean capture -> policy-to-manifest

say "(4) duo capture FAILS loudly, naming duo-agency-cpt's own unclassified keys (the loud-and-blocking gate)"
if OUT=$(wp_r1c1 duo capture --repo=/siterepo 2>&1); then
  fail "duo capture succeeded despite unclassified _duo_project_internal_notes/_duo_project_views — the gate did not fire"
fi
echo "$OUT"
grep -q '_duo_project_internal_notes' <<<"$OUT" || fail "gate output did not name _duo_project_internal_notes (got: $OUT)"
grep -q '_duo_project_views' <<<"$OUT" || fail "gate output did not name _duo_project_views (got: $OUT)"
pass "capture blocked loudly, naming both unclassified post-meta keys — ACF's own fields (image/relationship/taxonomy) did NOT block capture, confirming interplay point 3: the schema-driven interpreter typed them with zero manual classification"

say "(4) wp duo pending: journal-informed proposals + secret flag"
PENDING=$(wp_r1c1 duo pending --repo=/siterepo --format=json | tail -1)
echo "$PENDING" | jq .
check_pending() { echo "$PENDING" | jq -e ".[] | select($1)" >/dev/null 2>&1 || fail "$2"; }
echo "$PENDING" | jq -e '[.[] | select(.section=="post_meta" and .key=="_duo_project_internal_notes" and .proposal=="authored")] | length >= 1' >/dev/null \
  || fail "_duo_project_internal_notes not proposed authored"
echo "$PENDING" | jq -e '[.[] | select(.section=="post_meta" and .key=="_duo_project_internal_notes")][0].evidence.journal.n >= 1' >/dev/null \
  || fail "_duo_project_internal_notes has no journal evidence (expected the admin REST write to have been observed)"
echo "$PENDING" | jq -e '[.[] | select(.section=="post_meta" and .key=="_duo_project_views" and .proposal=="runtime")] | length >= 1' >/dev/null \
  || fail "_duo_project_views not proposed runtime"
echo "$PENDING" | jq -e '[.[] | select(.section=="options" and .key=="duo_agency_client_api_key" and ((.secret // "") | startswith("hard")))] | length >= 1' >/dev/null \
  || fail "duo_agency_client_api_key not flagged as a hard secret"
pass "pending reflects journal evidence: notes authored (journal-backed), views runtime, api_key hard-secret-flagged"

say "(4) classify: a hard secret must refuse authored without --allow-secret"
if OUT=$(wp_r1c1 duo classify --repo=/siterepo --set='options:duo_agency_client_api_key=authored' 2>&1); then
  fail "classify accepted duo_agency_client_api_key=authored despite it being a hard secret"
fi
pass "classify refused authored on the hard secret: $(tail -1 <<<"$OUT")"

say "(4) classify: the real decisions (one call, semicolon-joined, equals-form — wp-cli's two documented traps)"
wp_r1c1 duo classify --repo=/siterepo \
  --set='post_meta:_duo_project_internal_notes=authored;post_meta:_duo_project_views=runtime;options:duo_agency_client_api_key=env'
# DUO-3232 (env-bound value provisioning, bb5e23b, #34) made 'required' a
# mandatory explicit boolean on every class:"env" rule at
# Policy::validate_env_options() — no silent default either way. classify's
# own --set spec has no `required=` attribute (confirmed by reading
# Cli::parse_and_write_classify_spec(): only ref=/cast= are recognized,
# anything else throws "unknown option"), so this has to be a direct
# site.duo.json edit, same as every other fixture DUO-3269/DUO-3232's own
# sweep already touched (regress_pmpro_composite_ref.sh,
# regress_discovery_completeness.sh, regress_snapshot_meta.sh,
# regress_tec_regen.sh — all required:false). Matching that precedent:
# duo_agency_client_api_key is a fixture value with a harmless default
# (this section builds it, never expects an operator to provision it), and
# required:true would force an env-set provisioning step on r1c2 mid-round-
# trip, changing what section (2)'s cross-environment exercise actually
# tests — required:false is the fixture-appropriate choice, not a shortcut.
jq '.policy.options.duo_agency_client_api_key.required = false' siterepo/r1c1/site.duo.json > siterepo/r1c1/.tmp-site.json
mv siterepo/r1c1/.tmp-site.json siterepo/r1c1/site.duo.json
pass "classified: _duo_project_internal_notes=authored, _duo_project_views=runtime, duo_agency_client_api_key=env,required:false"

say "(4) capture succeeds now that every in-scope key is classified"
wp_r1c1 duo capture --repo=/siterepo
pass "capture succeeded"

say "(4) acceptance: no secret material anywhere in captured state; runtime views excluded; note captured"
if grep -rq 'sk_live_' siterepo/r1c1/state; then fail "sk_live_ secret leaked into captured state"; fi
if grep -rq '"_duo_project_views"' siterepo/r1c1/state; then fail "_duo_project_views (runtime) leaked into captured state"; fi
grep -rq 'Retainer renews' siterepo/r1c1/state/posts/project/*.md || fail "_duo_project_internal_notes missing from captured project state"
pass "no secret material anywhere under state/; runtime meta excluded; authored note present"

say "(4) policy-to-manifest: export duo-agency-cpt's own classifications, pin the manifest in place of inline policy, verify byte-identical re-capture (spike F's exact swap-and-verify pattern)"
# DUO-3362: export to a SCRATCH path and verify the committed manifest's
# classification sections still match — do NOT overwrite the committed file.
# Since DUO-3338 it also carries hand-authored `providers`/`actions` (the shipped
# proof a custom plugin can advertise a provider through the `duo_providers`
# filter) plus rationale `notes`, none of which `policy-to-manifest` emits; the
# old wholesale `> ../manifests/duo-agency-cpt.json` silently deleted them, and
# nothing failed until the live provider-contract regression next ran.
RAW=$($COMPOSE run --rm -T cli-r1c1 wp duo policy-to-manifest --repo=/siterepo --match='^_?duo_(project|agency)_?' --name=duo-agency-cpt)
printf '%s\n' "$RAW" | awk '/^\{/{f=1} f' > siterepo/r1c1/.tmp-agency-manifest-export.json
jq -e . siterepo/r1c1/.tmp-agency-manifest-export.json >/dev/null 2>&1 || fail "policy-to-manifest export for duo-agency-cpt is not valid JSON"
# Compare the export against the committed manifest restricted to the export's
# OWN top-level keys (exactly the classification sections policy-to-manifest
# emits), so the committed file's extra hand-authored providers/actions/notes
# are ignored by the comparison and preserved on disk. Canonical (jq -S) compare
# is order/whitespace-insensitive.
EXPORT_CANON=$(jq -S . siterepo/r1c1/.tmp-agency-manifest-export.json)
COMMITTED_PROJ=$(jq -S --slurpfile e siterepo/r1c1/.tmp-agency-manifest-export.json '
  ($e[0] | keys) as $ek | to_entries | map(select(.key as $k | $ek | index($k))) | from_entries
' ../manifests/duo-agency-cpt.json)
[ "$EXPORT_CANON" = "$COMMITTED_PROJ" ] \
  || fail "committed manifests/duo-agency-cpt.json's classification sections drifted from the policy-to-manifest export — regenerate the classifications while PRESERVING the hand-authored providers/actions/notes (never overwrite wholesale). export=$EXPORT_CANON committed_projection=$COMMITTED_PROJ"
jq -e '(.providers // [] | length) > 0 and (.actions // [] | length) > 0' ../manifests/duo-agency-cpt.json >/dev/null \
  || fail "committed manifests/duo-agency-cpt.json lost its hand-authored providers/actions blocks (DUO-3338) — the export must never overwrite them"
rm -f siterepo/r1c1/.tmp-agency-manifest-export.json
pass "policy-to-manifest export matches the committed manifest's classification sections; its hand-authored providers/actions/notes are preserved (DUO-3362: no wholesale overwrite)"

rm -rf siterepo/r1c1/.tmp-state-preswap
cp -r siterepo/r1c1/state siterepo/r1c1/.tmp-state-preswap
jq '
  .manifests += ["duo-agency-cpt"] |
  .policy.options |= with_entries(select(.key | test("^_?duo_agency_") | not)) |
  .policy.post_meta |= with_entries(select(.key | test("^_?duo_project_") | not))
' siterepo/r1c1/site.duo.json > siterepo/r1c1/.tmp-site-swapped.json
mv siterepo/r1c1/.tmp-site-swapped.json siterepo/r1c1/site.duo.json
wp_r1c1 duo capture --repo=/siterepo
diff -r siterepo/r1c1/.tmp-state-preswap siterepo/r1c1/state || fail "captured state changed after swapping inline policy for the exported manifest"
rm -rf siterepo/r1c1/.tmp-state-preswap
pass "policy == exported manifest: identical captured state either way — manifests/duo-agency-cpt.json is graduation-ready"

$GIT_1 add -A
$GIT_1 commit -qm "capture: agency content (ACF schema+fields, Elementor pages, 2 projects); classify + graduate duo-agency-cpt.json"
git -C siterepo/r1c1 push -q origin main
pass "content committed and pushed"

# ============================================================ round-trip r1c1 -> r1c2: hard lint gate, deploy, apply, render, isolation

say "(5) r1c2 pulls; wp duo lint as a HARD gate (must be zero findings — never soften this)"
git -C siterepo/r1c2 pull -q origin main
if ! wp_r1c2 duo lint --repo=/siterepo; then
  fail "duo lint reported findings on r1c2's pulled state — see output above; this must be fixed at the manifest/policy level, never bypassed"
fi
pass "lint: zero findings — ACF's interpreter-declared refs and Elementor's json_refs/text-tokenize leaves are all correctly owned"

say "(5) r1c2: plan + deploy (duo-agency-cpt already active from step 2 — expect a genuine no-op, matching Deploy::run()'s documented idempotency)"
DEPLOY_JSON=$(wp_r1c2 duo deploy --repo=/siterepo --format=json | tail -1)
echo "$DEPLOY_JSON" | jq -e '.activated == [] and .deactivated == [] and .theme_switched == null' >/dev/null \
  || fail "deploy was not a no-op on r1c2 despite duo-agency-cpt already being active (got: $DEPLOY_JSON)"
pass "deploy: genuine no-op (zero WP API calls) — activation already converged in step 2"

say "(5) r1c2: apply (canary must stay clean)"
REV=$(git -C siterepo/r1c2 rev-parse HEAD)
APPLY_JSON=$(wp_r1c2 duo apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REV" --format=json | tail -1)
[ "$(echo "$APPLY_JSON" | jq -r '.canary')" = "clean" ] || fail "apply's canary was not clean on r1c2 (got: $APPLY_JSON)"
pass "apply succeeded, canary clean"

say "(5) acceptance: canonical(r1c1) == canonical(r1c2), byte for byte"
wp_r1c1 duo capture --repo=/siterepo --out=/siterepo/.tmp-r1c1state >/dev/null
wp_r1c2 duo capture --repo=/siterepo --out=/siterepo/.tmp-r1c2state >/dev/null
diff -r siterepo/r1c1/.tmp-r1c1state siterepo/r1c2/.tmp-r1c2state || fail "round-trip mismatch between r1c1 and r1c2"
rm -rf siterepo/r1c1/.tmp-r1c1state siterepo/r1c2/.tmp-r1c2state
pass "canonical state identical: ACF refs remapped, Elementor json_refs/text-leaves remapped, project CPT meta interpreter-typed — across the CPT boundary, with zero engine changes"

PROJ_ALPHA_2=$(wp_r1c2 post list --post_type=project --name=skyline-rebrand --field=ID)
PROJ_BRAVO_2=$(wp_r1c2 post list --post_type=project --name=nimbus-app-launch --field=ID)
WORK_PAGE_2=$(wp_r1c2 post list --post_type=page --name=our-work --field=ID)
CTA_PAGE_2=$(wp_r1c2 post list --post_type=page --name=start-a-project --field=ID)

say "(5) INTERPLAY POINT 3, confirmed: get_field() on r1c2 resolves ACF's interpreter-typed fields on the CPT correctly"
HERO_ATT_2=$(wp_r1c2 eval "echo get_field('duo_project_hero', $PROJ_ALPHA_2);")
[ -n "$HERO_ATT_2" ] || fail "get_field(duo_project_hero) returned nothing on r1c2"
ALPHA_HERO_FILE=$(grep -l 'Duo R1C Skyline Hero' siterepo/r1c1/state/posts/attachment/*.md)
E1_MEDIA=$(grep '"media"' "$ALPHA_HERO_FILE" | sed 's/.*"media": "\([^"]*\)".*/\1/')
E1_SHA=${E1_MEDIA%.*}
HERO_FILE_REL=$(wp_r1c2 post meta get "$HERO_ATT_2" _wp_attached_file)
E2_SHA=$($COMPOSE run --rm -T cli-r1c2 bash -c "sha256sum /var/www/html/wp-content/uploads/$HERO_FILE_REL | cut -d' ' -f1")
[ "$E1_SHA" = "$E2_SHA" ] || fail "r1c2's duo_project_hero attachment content does not match r1c1's (got $E2_SHA, want $E1_SHA)"
pass "get_field(duo_project_hero) on r1c2 -> attachment #$HERO_ATT_2, byte-identical to r1c1's upload"

say "(5) INTERPLAY POINT 1, confirmed: get_field(duo_project_related) resolves to r1c2's OWN Elementor page ids, not r1c1's"
RELATED_2=$(wp_r1c2 eval "echo implode(',', (array) get_field('duo_project_related', $PROJ_ALPHA_2));")
[ "$RELATED_2" = "$WORK_PAGE_2" ] || fail "project alpha's duo_project_related on r1c2 is '$RELATED_2', expected r1c2's own Our Work id ($WORK_PAGE_2)"
RELATED_BRAVO_2=$(wp_r1c2 eval "echo implode(',', (array) get_field('duo_project_related', $PROJ_BRAVO_2));")
[ "$RELATED_BRAVO_2" = "$WORK_PAGE_2,$CTA_PAGE_2" ] || fail "project bravo's duo_project_related on r1c2 is '$RELATED_BRAVO_2', expected '$WORK_PAGE_2,$CTA_PAGE_2'"
pass "ACF relationship field pointing AT Elementor-built pages round-trips correctly — the ref-rewrite does not care which builder authored the target"

say "(5) dual-representation check: ACF's taxonomy field (save_terms=1) stays consistent with the NATIVE term relationship captured separately"
ACF_TAX_2=$(wp_r1c2 eval "echo implode(',', (array) get_field('duo_project_type_tax', $PROJ_ALPHA_2));")
NATIVE_TAX_2=$(wp_r1c2 post term list "$PROJ_ALPHA_2" project_type --field=term_id | sort -n | paste -sd, -)
ACF_TAX_SORTED=$(tr ',' '\n' <<<"$ACF_TAX_2" | sort -n | paste -sd, -)
[ "$ACF_TAX_SORTED" = "$NATIVE_TAX_2" ] || fail "ACF's own taxonomy field ($ACF_TAX_SORTED) and the native term relationship ($NATIVE_TAX_2) disagree on r1c2"
pass "ACF taxonomy field (postmeta, interpreter-typed) and the native wp_term_relationships (front-matter terms:) independently round-trip to the SAME answer on r1c2"

say "(5) INTERPLAY POINT 2, confirmed: render checks — Elementor's 'Our Work' page renders r1c2's own images/links, never r1c1's host, incl. a negative host-leak assertion"
FRONT=$(curl -fs "$R1C2/our-work/") || fail "r1c2 'Our Work' page did not return 200"
if grep -q 'localhost:8818' <<<"$FRONT"; then
  fail "r1c2's rendered 'Our Work' page leaks r1c1's host (localhost:8818) — _elementor_data ids/urls not fully rebound"
fi
grep -q "src=\"http://localhost:8819/wp-content/uploads/[0-9]\{4\}/[0-9]\{2\}/duo-r1c-hero-alpha[^\"]*\"" <<<"$FRONT" \
  || fail "Skyline hero image did not render from r1c2's own uploads on the Our Work page"
grep -q "src=\"http://localhost:8819/wp-content/uploads/[0-9]\{4\}/[0-9]\{2\}/duo-r1c-hero-bravo[^\"]*\"" <<<"$FRONT" \
  || fail "Nimbus hero image did not render from r1c2's own uploads on the Our Work page"
grep -q "href=\"http://localhost:8819/projects/skyline-rebrand/\"" <<<"$FRONT" \
  || fail "button link to Skyline Rebrand did not resolve to r1c2's own project permalink (interplay point 2)"
grep -q "href=\"http://localhost:8819/projects/nimbus-app-launch/\"" <<<"$FRONT" \
  || fail "button link to Nimbus App Launch did not resolve to r1c2's own project permalink (interplay point 2)"
pass "Our Work page: own-host images (incl. the same attachment ACF's own hero field also references) + own-host project-permalink button links; r1c1's host never leaked — negative assertion passed"

PROJ_FRONT=$(curl -fs "$R1C2/projects/skyline-rebrand/") || fail "r1c2's project single page did not return 200"
if grep -q 'localhost:8818' <<<"$PROJ_FRONT"; then
  fail "r1c2's rendered project page leaks r1c1's host"
fi
pass "project CPT single page (r1c2's OWN permalink structure, registered by the code/-deployed plugin) renders cleanly with no r1c1 host leak"

say "(5) REST route on r1c2 still responds (idempotent activation, not a one-time fluke)"
REST_CODE_2=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$R1C2/wp-json/duo-agency/v1/projects/$PROJ_ALPHA_2/notes")
[ "$REST_CODE_2" != "404" ] || fail "duo-agency/v1 route 404s on r1c2"
pass "REST route responds on r1c2 (HTTP $REST_CODE_2)"

say "(6) runtime isolation: r1c2's OWN anonymous view count is independent of r1c1's, both before and after apply"
# NOTE: an earlier render check (the project CPT single-page curl a few
# steps up) already bumped r1c2's own _duo_project_views by 1 — asserting
# deltas rather than an assumed absolute baseline is the correct, robust
# check here; the property under test is independence, not a magic number.
R1C2_VIEWS_PRE=$(wp_r1c2 post meta get "$PROJ_ALPHA_2" _duo_project_views 2>/dev/null || echo 0)
for _ in $(seq 1 3); do curl -fso /dev/null "$R1C2/projects/skyline-rebrand/"; done
R1C2_VIEWS_AFTER=$(wp_r1c2 post meta get "$PROJ_ALPHA_2" _duo_project_views)
[ "$R1C2_VIEWS_AFTER" = "$((R1C2_VIEWS_PRE + 3))" ] \
  || fail "r1c2's own _duo_project_views did not independently increase by exactly 3 (was $R1C2_VIEWS_PRE, now $R1C2_VIEWS_AFTER)"
R1C1_VIEWS_PRE=$(wp_r1c1 post meta get "$PROJ_ALPHA" _duo_project_views)
for _ in $(seq 1 4); do curl -fso /dev/null "$ALPHA_URL"; done
R1C1_VIEWS_AFTER=$(wp_r1c1 post meta get "$PROJ_ALPHA" _duo_project_views)
[ "$R1C1_VIEWS_AFTER" = "$((R1C1_VIEWS_PRE + 4))" ] \
  || fail "r1c1's own _duo_project_views did not independently increase by exactly 4 (was $R1C1_VIEWS_PRE, now $R1C1_VIEWS_AFTER)"
[ "$(wp_r1c2 post meta get "$PROJ_ALPHA_2" _duo_project_views)" = "$R1C2_VIEWS_AFTER" ] \
  || fail "r1c2's _duo_project_views changed after r1c1's OWN later traffic — runtime isolation violated"
pass "runtime meta (post-meta grain, not just options) never crosses environments in either direction: r1c1=$R1C1_VIEWS_AFTER, r1c2=$R1C2_VIEWS_AFTER, both correct and independent"

say "(6) runtime isolation: duo_agency_client_api_key (env-classified secret) never transits through git or apply"
R1C2_KEY=$(wp_r1c2 option get duo_agency_client_api_key 2>/dev/null || echo '')
[ -z "$R1C2_KEY" ] || fail "duo_agency_client_api_key leaked onto r1c2 (got: $R1C2_KEY) — env-classified values must never apply"
pass "duo_agency_client_api_key stays exactly where it was set (r1c1 only); r1c2 has no value for it at all"

# ============================================================ divergent-edit merge: clean, then a genuine conflict

say "(7a) divergent merge, CLEAN case: two branches edit project alpha's DIFFERENT ACF fields — front-matter merge must be clean (canonical JSON is one key per line)"
$GIT_1 checkout -q main
$GIT_1 checkout -qb branch-tax main
wp_r1c1 eval "update_field('duo_project_type_tax', [$WEB_ID, $BRAND_ID, $MOBILE_ID], $PROJ_ALPHA);"
wp_r1c1 duo capture --repo=/siterepo
$GIT_1 add -A
$GIT_1 commit -qm "branch-tax: add Mobile App to Skyline Rebrand's project type"
git -C siterepo/r1c1 push -qu origin branch-tax

$GIT_2 fetch -q origin
$GIT_2 checkout -qB branch-related origin/main
wp_r1c2 eval "update_field('duo_project_related', [$WORK_PAGE_2, $CTA_PAGE_2], $PROJ_ALPHA_2);"
wp_r1c2 duo capture --repo=/siterepo
$GIT_2 add -A
$GIT_2 commit -qm "branch-related: add Start a Project to Skyline Rebrand's related pages"
git -C siterepo/r1c2 push -qu origin branch-related

$GIT_1 checkout -q main
$GIT_1 merge -q branch-tax
git -C siterepo/r1c1 fetch -q origin branch-related
set +e
$GIT_1 merge origin/branch-related >/tmp/r1c-merge-clean.log 2>&1
CLEAN_MERGE_RC=$?
set -e
cat /tmp/r1c-merge-clean.log
[ "$CLEAN_MERGE_RC" -eq 0 ] || fail "expected a CLEAN merge (different ACF fields on the same project), got a conflict"
ALPHA_FILE=$(ls siterepo/r1c1/state/posts/project/*--skyline-rebrand.md)
grep -q '<<<<<<<' "$ALPHA_FILE" && fail "conflict markers present despite the merge reporting success"
jq -e --arg mid "{{term:" '.meta.duo_project_type_tax | length == 3' <(sed -n '2,/^---$/p' "$ALPHA_FILE" | sed '$d') >/dev/null \
  || fail "merged file does not carry all 3 project_type tokens"
jq -e '.meta.duo_project_related | length == 2' <(sed -n '2,/^---$/p' "$ALPHA_FILE" | sed '$d') >/dev/null \
  || fail "merged file does not carry both related-page tokens"
pass "clean git merge: both branches' DIFFERENT ACF fields (taxonomy + relationship) landed in the SAME merged file, zero conflict markers"

git -C siterepo/r1c1 push -q origin main
git -C siterepo/r1c2 fetch -q origin && git -C siterepo/r1c2 checkout -q main && git -C siterepo/r1c2 pull -q origin main

REV=$(git -C siterepo/r1c1 rev-parse HEAD)
wp_r1c1 duo apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REV" >/dev/null
REV=$(git -C siterepo/r1c2 rev-parse HEAD)
wp_r1c2 duo apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REV" >/dev/null

TAX_1=$(wp_r1c1 eval "echo implode(',', (array) get_field('duo_project_type_tax', $PROJ_ALPHA));")
TAX_2=$(wp_r1c2 eval "echo implode(',', (array) get_field('duo_project_type_tax', $PROJ_ALPHA_2));")
[ "$(tr ',' '\n' <<<"$TAX_1" | sort -n | paste -sd, -)" = "$(tr ',' '\n' <<<"$TAX_2" | sort -n | paste -sd, -)" ] \
  || fail "post-merge project_type diverges between r1c1 ($TAX_1) and r1c2 ($TAX_2)"
REL_1=$(wp_r1c1 eval "echo count((array) get_field('duo_project_related', $PROJ_ALPHA));")
REL_2=$(wp_r1c2 eval "echo count((array) get_field('duo_project_related', $PROJ_ALPHA_2));")
[ "$REL_1" = "2" ] && [ "$REL_2" = "2" ] || fail "post-merge duo_project_related count is not 2 on both envs (r1c1=$REL_1, r1c2=$REL_2)"
pass "both environments converged: project alpha carries BOTH branches' edits after apply"

say "(7b) divergent merge, CONFLICT case: two branches edit project bravo's SAME ACF field (hero image) to DIFFERENT images"
make_hero() { # make_hero <env> <r> <g> <b> <proj> <label> -> echoes new attachment id, sets duo_project_hero
  local env="$1" r="$2" g="$3" b="$4" proj="$5" label="$6"
  cat > "siterepo/${env}/.tmp-hero-${label}.php" <<PHPEOF
<?php
\$im = imagecreatetruecolor(96, 64);
imagefilledrectangle(\$im, 0, 0, 95, 63, imagecolorallocate(\$im, $r, $g, $b));
\$path = '/tmp/duo-r1c-hero-${label}.png';
imagepng(\$im, \$path);
imagedestroy(\$im);
\$id = media_handle_sideload(['name' => basename(\$path), 'tmp_name' => \$path], 0, 'Duo R1C Hero ${label}');
if (is_wp_error(\$id)) { fwrite(STDERR, \$id->get_error_message()); exit(1); }
update_field('duo_project_hero', (int) \$id, $proj);
echo (int) \$id;
PHPEOF
  wp_env "$env" eval-file "/siterepo/.tmp-hero-${label}.php"
  rm -f "siterepo/${env}/.tmp-hero-${label}.php"
}

$GIT_1 checkout -q main
$GIT_1 checkout -qb branch-hero-a main
NEW_HERO_A=$(make_hero r1c1 10 200 10 "$PROJ_BRAVO" hero-a)
wp_r1c1 duo capture --repo=/siterepo
$GIT_1 add -A
$GIT_1 commit -qm "branch-hero-a: swap Nimbus hero to image A (attachment #$NEW_HERO_A)"
git -C siterepo/r1c1 push -qu origin branch-hero-a

$GIT_2 fetch -q origin
$GIT_2 checkout -qB branch-hero-b origin/main
NEW_HERO_B=$(make_hero r1c2 200 10 200 "$PROJ_BRAVO_2" hero-b)
wp_r1c2 duo capture --repo=/siterepo
$GIT_2 add -A
$GIT_2 commit -qm "branch-hero-b: swap Nimbus hero to image B (attachment #$NEW_HERO_B)"
git -C siterepo/r1c2 push -qu origin branch-hero-b

$GIT_1 checkout -q main
$GIT_1 merge -q branch-hero-a
git -C siterepo/r1c1 fetch -q origin branch-hero-b
set +e
$GIT_1 merge origin/branch-hero-b >/tmp/r1c-merge-conflict.log 2>&1
CONFLICT_RC=$?
set -e
cat /tmp/r1c-merge-conflict.log
[ "$CONFLICT_RC" -ne 0 ] || fail "expected a genuine git conflict (same ACF field, different images) — merge succeeded instead"
BRAVO_FILE=$(ls siterepo/r1c1/state/posts/project/*--nimbus-app-launch.md)
grep -q '<<<<<<<' "$BRAVO_FILE" || fail "no conflict markers in the Nimbus project file"
CONFLICTS=$(git -C siterepo/r1c1 status --porcelain | grep '^UU' || true)
echo "$CONFLICTS"
[ "$(echo "$CONFLICTS" | wc -l | tr -d ' ')" = "1" ] || fail "expected exactly one conflicted entity (Nimbus), got: $CONFLICTS"
grep -q -- '--nimbus-app-launch.md' <<<"$CONFLICTS" || fail "the conflict is not on the Nimbus project file"
pass "genuine git conflict, scoped to exactly the Nimbus project file's duo_project_hero line — resolved the same way a code conflict would be"

say "(7b) resolve: editorial call — keep image B (branch-hero-b, r1c2's edit)"
git -C siterepo/r1c1 checkout --theirs -- "state/posts/project/$(basename "$BRAVO_FILE")"
git -C siterepo/r1c1 add -A
$GIT_1 commit -qm "merge branch-hero-b: resolve Nimbus hero conflict, keep image B (#$NEW_HERO_B)"
git -C siterepo/r1c1 push -q origin main

git -C siterepo/r1c2 fetch -q origin && git -C siterepo/r1c2 checkout -q main && git -C siterepo/r1c2 pull -q origin main
REV=$(git -C siterepo/r1c1 rev-parse HEAD)
wp_r1c1 duo apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REV" >/dev/null
REV=$(git -C siterepo/r1c2 rev-parse HEAD)
wp_r1c2 duo apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REV" >/dev/null

HERO_FINAL_1=$(wp_r1c1 eval "echo get_field('duo_project_hero', $PROJ_BRAVO);")
HERO_FINAL_2=$(wp_r1c2 eval "echo get_field('duo_project_hero', $PROJ_BRAVO_2);")
HERO_FINAL_1_SHA=$($COMPOSE run --rm -T cli-r1c1 bash -c "F=\$(wp --path=/var/www/html post meta get $HERO_FINAL_1 _wp_attached_file); sha256sum /var/www/html/wp-content/uploads/\$F | cut -d' ' -f1")
HERO_FINAL_2_SHA=$($COMPOSE run --rm -T cli-r1c2 bash -c "F=\$(wp --path=/var/www/html post meta get $HERO_FINAL_2 _wp_attached_file); sha256sum /var/www/html/wp-content/uploads/\$F | cut -d' ' -f1")
[ "$HERO_FINAL_1_SHA" = "$HERO_FINAL_2_SHA" ] || fail "post-conflict-resolution hero image content diverges between environments"
pass "both environments converged on the resolved hero image (byte-identical content) after the conflict"

say "(7) final convergence: canonical(r1c1) == canonical(r1c2)"
wp_r1c1 duo capture --repo=/siterepo --out=/siterepo/.tmp-final1 >/dev/null
wp_r1c2 duo capture --repo=/siterepo --out=/siterepo/.tmp-final2 >/dev/null
diff -r siterepo/r1c1/.tmp-final1 siterepo/r1c2/.tmp-final2 || fail "environments did not converge after the merge scenarios"
rm -rf siterepo/r1c1/.tmp-final1 siterepo/r1c2/.tmp-final2
pass "environments byte-identical after both merge scenarios"

printf '\n\033[1;32m✔ GRIND R1-C (agency stack interplay) PASSED\033[0m\n'
