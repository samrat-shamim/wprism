# Exact-artifact certification workflow for Hustle.
#
# Two cases, both against digest-pinned official artifacts: 7.8.14.2 is the
# admitted contract and must round-trip; 7.8.14.1 is the adjacent official
# release one patch below it and must be REFUSED by name without touching a
# single authored row. Hustle keeps no post type, so every assertion here is
# about the custom-table pair Hustle_Db creates.

seed_hustle_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1
}

check_hustle_boundary_content() { # <wp1|wp2> <label>
  local cli="$1" label="$2" out
  out=$("$cli" eval '
    global $wpdb;
    $id = (int) $wpdb->get_var("SELECT module_id FROM {$wpdb->prefix}hustle_modules WHERE module_name = \x27WPrism Embed Fixture\x27");
    if ( ! $id ) { throw new RuntimeException("hustle module missing"); }
    $m = Hustle_Module_Model::new_instance( $id );
    if ( is_wp_error( $m ) ) { throw new RuntimeException("hustle model refused: " . $m->get_error_message()); }
    $c = $m->get_content()->to_array();
    echo wp_json_encode([
      "module" => $id,
      "name" => $m->module_name,
      "type" => $m->module_type,
      "title" => isset($c["title"]) ? $c["title"] : null,
      "shortcode_id" => $m->get_meta("shortcode_id"),
      "meta_rows" => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hustle_modules_meta WHERE module_id = %d", $id)),
    ], JSON_UNESCAPED_SLASHES);
  ')
  require_observed_nonempty "Hustle $label module APIs" "$out"
  out=$(printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }')
  printf '%s\n' "$out" | jq -e '
    .name == "WPrism Embed Fixture" and .type == "embedded" and
    .title == "WPrism fixture title" and .shortcode_id == "wprism-embed-fixture" and
    .meta_rows >= 4
  ' >/dev/null || fail "Hustle $label module APIs do not consume the exact rows: $out"
  printf '%s\n' "$out"
  pass "Hustle $label exact artifact loads the module through its own model with content, shortcode id and meta intact"
}

VMATRIX_PLUGIN_SLUG=wordpress-popup

version_matrix_reset_after_delete() {
  local cli="$1"
  # Hustle's tables are created by its activation hook; a deleted plugin leaves
  # them behind, and a stale module row would make the next case's premise
  # untrue rather than fresh.
  "$cli" db query "
    DROP TABLE IF EXISTS wp_hustle_modules_meta;
    DROP TABLE IF EXISTS wp_hustle_modules;
    DROP TABLE IF EXISTS wp_hustle_entries_meta;
    DROP TABLE IF EXISTS wp_hustle_entries;
    DROP TABLE IF EXISTS wp_hustle_tracking;
    DELETE FROM wp_options WHERE option_name LIKE 'hustle\_%' OR option_name LIKE 'hustle-%';
  " >/dev/null 2>&1 || true
}

version_matrix_workflow() {
VMATRIX_CASES=$((VMATRIX_CASES + 1))
HUSTLE_VERSION=7.8.14.2
say "boundary: wordpress-popup $HUSTLE_VERSION (the admitted contract)"

reset_env wp1
reset_env wp2
reset_case_repositories

say "fetch + verify wordpress-popup $HUSTLE_VERSION (digest-checked artifact only)"
HUSTLE_ARTIFACT_1=$(fetch_artifact wordpress-popup "$HUSTLE_VERSION" cli1)
HUSTLE_ARTIFACT_2=$(fetch_artifact wordpress-popup "$HUSTLE_VERSION" cli2)
wp1 plugin install "$HUSTLE_ARTIFACT_1" --activate >/dev/null
HUSTLE_INSTALLED_1=$(wp1 plugin get wordpress-popup --field=version)
[ "$HUSTLE_INSTALLED_1" = "$HUSTLE_VERSION" ] \
  || fail "side 1 installed version mismatch: expected $HUSTLE_VERSION, got $HUSTLE_INSTALLED_1"
pass "side 1: wordpress-popup $HUSTLE_VERSION installed from verified artifact, active"

cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "wordpress-popup"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: Hustle $HUSTLE_VERSION exact-boundary certification"
"${GIT1[@]}" push -qu origin main

seed_hustle_content
SOURCE_OBSERVATION=$(check_hustle_boundary_content wp1 source)
SOURCE_MODULE_ID=$(printf '%s\n' "$SOURCE_OBSERVATION" | grep -o '{"module":[0-9]*' | head -1 | tr -dc '0-9')
require_observed_nonempty "Hustle source module id" "$SOURCE_MODULE_ID"
wp1 wprism capture --repo=/siterepo
wp1 wprism lint --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: Hustle $HUSTLE_VERSION modules"
"${GIT1[@]}" push -q origin main

clone_case_target
wp2 plugin install "$HUSTLE_ARTIFACT_2" >/dev/null
INSTALLED_2=$(wp2 plugin get wordpress-popup --field=version)
require_fixture_values INSTALLED_2
[ "$INSTALLED_2" = "$HUSTLE_VERSION" ] \
  || fail "side 2 installed version mismatch: expected $HUSTLE_VERSION, got $INSTALLED_2"
wp2 wprism deploy --repo=/siterepo
REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
assert_version_matrix_apply_ready
grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
  || fail "apply canary not clean at wordpress-popup $HUSTLE_VERSION"
TARGET_OBSERVATION=$(check_hustle_boundary_content wp2 target)
TARGET_MODULE_ID=$(printf '%s\n' "$TARGET_OBSERVATION" | grep -o '{"module":[0-9]*' | head -1 | tr -dc '0-9')
require_observed_nonempty "Hustle target module id" "$TARGET_MODULE_ID"
# The seed burns source ids, so the two sides must land on different
# auto-increments; equal ids would make the embed assertion vacuous.
[ "$SOURCE_MODULE_ID" != "$TARGET_MODULE_ID" ] \
  || fail "source and target module ids are both $TARGET_MODULE_ID, so this case cannot prove reference rebinding"
EMBED_TARGET=$(wp2 eval '$p = get_page_by_path("wprism-hustle-embed", OBJECT, "page"); echo $p ? $p->post_content : "";')
grep -Fq "[wd_hustle id=\"$TARGET_MODULE_ID\"" <<<"$EMBED_TARGET" \
  || fail "applied embed does not address the target module id $TARGET_MODULE_ID (source was $SOURCE_MODULE_ID): $EMBED_TARGET"
pass "Hustle $HUSTLE_VERSION deploys, applies, and rebinds the embed from source module $SOURCE_MODULE_ID to target module $TARGET_MODULE_ID"

wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-final
HUSTLE_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
rm -rf "siterepo/${PAIR}2/.tmp-final"
[ -z "$HUSTLE_DIFF" ] \
  || fail "byte-identity broken at wordpress-popup $HUSTLE_VERSION: $HUSTLE_DIFF"
pass "Hustle $HUSTLE_VERSION recaptures byte-identically across environments"

say "negative control: wordpress-popup 7.8.14.1 (adjacent official release below the exact contract) must be REFUSED"
reset_env wp1
reset_case_repositories

HUSTLE_IN_RANGE=$(fetch_artifact wordpress-popup 7.8.14.2 cli1)
wp1 plugin install "$HUSTLE_IN_RANGE" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get wordpress-popup --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = "7.8.14.2" ] \
  || fail "negative control premise did not install exact wordpress-popup 7.8.14.2 bytes"
cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "wordpress-popup"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: Hustle negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_hustle_content
wp1 wprism capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid Hustle state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate wordpress-popup >/dev/null
wp1 plugin delete wordpress-popup >/dev/null
HUSTLE_OUT_OF_RANGE=$(fetch_artifact wordpress-popup 7.8.14.1 cli1)
wp1 plugin install "$HUSTLE_OUT_OF_RANGE" >/dev/null
INSTALLED_OOR=$(wp1 plugin get wordpress-popup --field=version)
[ "$INSTALLED_OOR" = "7.8.14.1" ] \
  || fail "negative control: expected wordpress-popup 7.8.14.1 installed, got $INSTALLED_OOR"
HUSTLE_REFUSAL_BEFORE=$(wp1 eval '
  global $wpdb;
  echo hash("sha256", maybe_serialize([
    $wpdb->get_results("SELECT * FROM {$wpdb->prefix}hustle_modules ORDER BY module_id", ARRAY_A),
    $wpdb->get_results("SELECT module_id, meta_key, meta_value FROM {$wpdb->prefix}hustle_modules_meta ORDER BY meta_id", ARRAY_A),
  ]));')
require_observed_nonempty "Hustle refusal state baseline" "$HUSTLE_REFUSAL_BEFORE"
set +e
DEPLOY_OUT=$(wp1 wprism deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] \
  || fail "expected deploy to refuse wordpress-popup 7.8.14.1, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "wordpress-popup 7.8.14.1 refused for the wrong reason (got: $DEPLOY_OUT)"
grep -q "wordpress-popup/popover.php" <<<"$DEPLOY_OUT" \
  || fail "Hustle refusal did not name the exact basename (got: $DEPLOY_OUT)"
grep -q "7.8.14.1" <<<"$DEPLOY_OUT" \
  || fail "Hustle refusal did not name installed version 7.8.14.1 (got: $DEPLOY_OUT)"
if wp1 plugin is-active wordpress-popup >/dev/null 2>&1; then
  fail "outside-range wordpress-popup 7.8.14.1 was activated before refusal"
fi
HUSTLE_REFUSAL_AFTER=$(wp1 eval '
  global $wpdb;
  echo hash("sha256", maybe_serialize([
    $wpdb->get_results("SELECT * FROM {$wpdb->prefix}hustle_modules ORDER BY module_id", ARRAY_A),
    $wpdb->get_results("SELECT module_id, meta_key, meta_value FROM {$wpdb->prefix}hustle_modules_meta ORDER BY meta_id", ARRAY_A),
  ]));')
[ "$HUSTLE_REFUSAL_AFTER" = "$HUSTLE_REFUSAL_BEFORE" ] \
  || fail "Hustle outside-range refusal mutated authored module or module-meta rows"
printf '%s\n' "$DEPLOY_OUT"
pass "official wordpress-popup 7.8.14.1 is loudly refused, stays inactive, and cannot mutate a single authored module row"
}
