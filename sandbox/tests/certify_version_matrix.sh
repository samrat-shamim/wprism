#!/usr/bin/env bash
# Certify version-boundary matrix (DUO-3223's own last remaining piece,
# unblocked by the owner ruling on artifact sourcing — issue comment
# 0ec1d2e3). No existing conformance/grind fixture installs a plugin at
# anything other than "whatever wp.org currently serves for this slug" —
# this is the first proof that a manifest's own declared version_range is
# backed by real evidence at ITS OWN edges, not just the one version every
# other fixture happens to exercise.
#
# First real plugin only (ACF, manifests/acf.json's own [6.0.0, 7.0.0)
# range) — proving the artifact-sourcing mechanism itself (sandbox/bin/
# fetch-artifact.sh, sandbox/conformance/artifacts.lock.json) end to end
# against the real product path. The other 6 pinned manifests are this
# issue's own next slice, scope-accounted at claim time rather than
# promised here.
#
# For EACH boundary version (6.0.0 min, 6.8.7 max-practical — both real,
# currently-existing wp.org releases, confirmed live against the plugin-
# info API, never invented): fresh pair, install ONLY from a digest-
# verified artifact (never a bare `wp plugin install acf --activate`,
# which would silently pull whatever is current), seed real ACF content
# (the same field group/fields conformance/seeds/acf.sh already proves —
# reused, not reinvented), capture, round-trip apply, byte-identical
# recapture. A failure at either boundary is exactly what this issue's own
# non-negotiable ("the harness installs exact artifacts; it never pulls
# latest") exists to catch before a manifest's own claimed range is trusted.
#
# Own dedicated pair (vmatrix1 :8870 / vmatrix2 :8871 by default; agents set
# VMATRIX_PAIR and explicit ports), destroyed only after every assertion below
# passes (docs/sandbox.md's own convention) — a failing run leaves it up for
# inspection.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

PAIR="${VMATRIX_PAIR:-vmatrix}"
PORT1="${VMATRIX_PORT1:-8870}"
PORT2="${VMATRIX_PORT2:-8871}"
export DUO_PAIR="$PAIR"
PAIR_COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml -f pair.artifacts.yml)
wp1() { "${PAIR_COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${PAIR_COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
GIT1=(git -C "siterepo/${PAIR}1" -c user.name=duo-vmatrix1 -c user.email=vmatrix1@example.test)

. bin/fetch-artifact.sh

say "boot pair $PAIR (${PAIR}1 :$PORT1 / ${PAIR}2 :$PORT2), idempotent"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless
pass "pair up"

seed_acf_content() { # seed_acf_content <cli-fn>
  local cli="$1"
  cat > "siterepo/${PAIR}1/.tmp-seed-acf.php" <<'PHPEOF'
<?php
if (!function_exists('acf_update_field_group')) {
    fwrite(STDERR, "ACF functions not available\n");
    exit(1);
}
acf_update_field_group([
    'key' => 'group_duo_demo', 'title' => 'Duo Demo', 'fields' => [],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
    'menu_order' => 0, 'position' => 'normal', 'style' => 'default',
    'label_placement' => 'top', 'instruction_placement' => 'label', 'active' => true,
]);
$group_posts = get_posts(['post_type' => 'acf-field-group', 'name' => 'group_duo_demo', 'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'any']);
$group_id = $group_posts ? (int) $group_posts[0] : 0;
if (!$group_id) { fwrite(STDERR, "field group not created\n"); exit(1); }
acf_update_field([
    'key' => 'field_duo_related', 'label' => 'Related', 'name' => 'duo_related',
    'type' => 'relationship', 'parent' => $group_id, 'post_type' => ['post'], 'return_format' => 'id',
]);
$target = wp_insert_post([
    'post_type' => 'post', 'post_status' => 'publish',
    'post_title' => 'Version Matrix Related Target', 'post_name' => 'vmatrix-related-target',
    'post_content' => "<!-- wp:paragraph -->\n<p>Relationship target.</p>\n<!-- /wp:paragraph -->",
], true);
if (is_wp_error($target)) { fwrite(STDERR, "target post insert failed\n"); exit(1); }
$content_id = wp_insert_post([
    'post_type' => 'post', 'post_status' => 'publish',
    'post_title' => 'Version Matrix ACF Content', 'post_name' => 'vmatrix-acf-content',
    'post_content' => "<!-- wp:paragraph -->\n<p>Carries an ACF field.</p>\n<!-- /wp:paragraph -->",
], true);
if (is_wp_error($content_id)) { fwrite(STDERR, "content post insert failed\n"); exit(1); }
update_field('duo_related', [$target], $content_id);
echo json_encode(['group' => $group_id, 'target' => $target, 'content' => $content_id]) . "\n";
PHPEOF
  local seed_out
  seed_out=$("$cli" eval-file /siterepo/.tmp-seed-acf.php)
  rm -f "siterepo/${PAIR}1/.tmp-seed-acf.php"
  echo "acf seed: $seed_out"
}

reset_env() { # reset_env <cli-fn> — content + identity only, keeps WordPress
  # core/theme installed and the site "installed" (unlike `pair.sh reset`,
  # which drops the database entirely and leaves the site UNINSTALLED until
  # `pair.sh up` runs again — the right tool between SCRIPT runs, the wrong
  # one for a same-run per-boundary-version loop iteration, confirmed the
  # hard way on this script's own first live attempt: "Error: The site you
  # have requested is not installed"). Matches grind_r1b_shop.sh/grind_r3b_
  # events.sh's own reset_env_state() convention, adapted for a single side.
  local cli="$1"
  "$cli" site empty --yes >/dev/null
  "$cli" plugin deactivate advanced-custom-fields >/dev/null 2>&1 || true
  "$cli" plugin delete advanced-custom-fields >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_duo_map" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_duo_state" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_duo_kv" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_duo_journal" >/dev/null 2>&1 || true
}

for ACF_VERSION in 6.0.0 6.8.7; do
  say "boundary: acf $ACF_VERSION"

  reset_env wp1
  reset_env wp2
  rm -rf "siterepo/origin-$PAIR.git" "siterepo/${PAIR}1" "siterepo/${PAIR}2"
  git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
  mkdir -p "siterepo/${PAIR}1"

  say "fetch + verify acf $ACF_VERSION (never a bare slug install — always a digest-checked artifact)"
  ARTIFACT_1=$(fetch_artifact advanced-custom-fields "$ACF_VERSION" cli1)
  ARTIFACT_2=$(fetch_artifact advanced-custom-fields "$ACF_VERSION" cli2)
  pass "verified sha256-pinned artifact resolved for both sides: $ARTIFACT_1"

  wp1 plugin install "$ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(wp1 plugin get advanced-custom-fields --field=version)
  [ "$INSTALLED_1" = "$ACF_VERSION" ] || fail "side 1 installed version mismatch: expected $ACF_VERSION, got $INSTALLED_1"
  pass "side 1: acf $ACF_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.duo.json" <<EOF
{
  "manifests": ["core", "acf"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "acf-field-group", "acf-field"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: acf $ACF_VERSION version-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_acf_content wp1

  wp1 duo capture --repo=/siterepo
  pass "captured on side 1 (acf $ACF_VERSION)"

  wp1 duo lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: acf $ACF_VERSION content"
  "${GIT1[@]}" push -q origin main

  git clone -q "siterepo/origin-$PAIR.git" "siterepo/${PAIR}2"
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get advanced-custom-fields --field=version)
  [ "$INSTALLED_2" = "$ACF_VERSION" ] || fail "side 2 installed version mismatch: expected $ACF_VERSION, got $INSTALLED_2"

  wp2 duo deploy --repo=/siterepo
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  # --adopt-by-slug=terms,posts: WordPress core's own defaults (the
  # "Uncategorized" category always, a "Hello World" post/"Sample Page" on
  # some installs) survive `site empty --yes` and collide by slug with the
  # captured state's own entities of the same name — the same known,
  # expected pattern every other grind/certify pair script in this repo
  # already handles the identical way (grind_r3b_events.sh, grind_r1b_shop.sh).
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee /tmp/vmatrix_apply.txt
  grep -q 'canary clean' /tmp/vmatrix_apply.txt || fail "apply canary not clean at acf $ACF_VERSION"
  pass "deploy + apply succeeded on side 2 (acf $ACF_VERSION, canary clean)"

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at acf $ACF_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at acf $ACF_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"
done

# Team-lead's own requirement: the loop above proves every IN-RANGE boundary
# certifies — it does not by itself prove the pin is honest, i.e. that an
# OUT-OF-range version is actually refused rather than silently accepted.
# Both properties together are what "the matrix proves the pins honest, not
# just the plugin functional" means. Deploy::code_mismatch()
# (agent/src/Deploy.php) is the real enforcement: it reads the ACTUALLY-
# installed plugin version via WordPress's own get_plugins(), compares it
# against the manifest's declared version_range, and — triggered by both
# `wp duo deploy` and `wp duo apply` — throws an 'outside_version_range'
# finding naming the plugin, its installed version, and the declared range,
# unless --force-code-mismatch is passed. This only needs `duo deploy`
# (code-only reconciliation), not a full capture/apply round-trip — the
# refusal fires before any target mutation is attempted.
say "negative control: acf 5.12.6 (real wp.org release, genuinely below manifests/acf.json's own declared min 6.0.0) must be REFUSED, not silently accepted"
reset_env wp1
rm -rf "siterepo/origin-$PAIR.git" "siterepo/${PAIR}1" "siterepo/${PAIR}2"
git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
mkdir -p "siterepo/${PAIR}1"

OUT_OF_RANGE_ARTIFACT=$(fetch_artifact advanced-custom-fields 5.12.6 cli1)
wp1 plugin install "$OUT_OF_RANGE_ARTIFACT" --activate >/dev/null
INSTALLED_OOR=$(wp1 plugin get advanced-custom-fields --field=version)
[ "$INSTALLED_OOR" = "5.12.6" ] || fail "negative control: expected acf 5.12.6 installed, got $INSTALLED_OOR"

cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "acf"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "acf-field-group", "acf-field"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: acf negative-control pin, out-of-range plugin installed"
"${GIT1[@]}" push -qu origin main

# `duo deploy` compiles the repository before it ever reaches code_mismatch()
# and refuses loudly if state/ doesn't exist yet ([state_directory_missing])
# — found live on this section's own first attempt. A real capture (harmless
# with the out-of-range plugin installed: capture itself never checks
# version_range, only deploy/apply do — confirmed by direct read of
# Deploy::code_mismatch()'s own call sites) produces a valid state/ tree
# cheaply, with zero ACF-specific content since nothing has been seeded.
wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: empty state, acf 5.12.6 still installed"
"${GIT1[@]}" push -q origin main

set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse acf 5.12.6 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
echo "$DEPLOY_OUT" | grep -q "outside_version_range\|outside the '.*' manifest's declared version_range" \
  || fail "deploy refused, but not for the expected outside_version_range reason (got: $DEPLOY_OUT)"
echo "$DEPLOY_OUT" | grep -q "advanced-custom-fields/acf.php" || fail "refusal did not name the plugin (got: $DEPLOY_OUT)"
echo "$DEPLOY_OUT" | grep -q "5.12.6" || fail "refusal did not name the actually-installed version (got: $DEPLOY_OUT)"
echo "$DEPLOY_OUT"
pass "confirmed: acf 5.12.6 (real, installed, genuinely below the declared min) is loudly refused by Deploy::code_mismatch() — the version_range pin is honest, not just decorative"

say "cleanup"
bash bin/pair.sh destroy "$PAIR"
pass "destroyed $PAIR (every assertion above passed)"

printf '\n\033[1;32m✔ CERTIFY_VERSION_MATRIX PASSED\033[0m\n'
