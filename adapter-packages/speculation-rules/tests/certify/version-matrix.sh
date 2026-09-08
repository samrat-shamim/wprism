seed_speculation_rules_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1
}

check_speculation_rules_boundary_content() {
  local out
  out=$(wp2 eval '
    wp_set_current_user(1);
    $stored = plsr_get_stored_setting_value();
    echo wp_json_encode([
      "config" => wp_get_speculation_rules_configuration(),
      "default" => plsr_get_setting_default(),
      "enabled" => plsr_is_speculative_loading_enabled(),
      "keys" => array_keys($stored),
      "stored" => $stored,
      "version" => SPECULATION_RULES_VERSION,
    ]);
  ')
  require_observed_nonempty "Speculative Loading boundary target behavior" "$out"
  out=$(printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }')
  printf '%s\n' "$out" | jq -e '
    .version == "1.7.0" and
    .stored.mode == "prefetch" and .stored.eagerness == "conservative" and
    .stored.authentication == "logged_out_and_admins" and
    .keys == ["mode", "eagerness", "authentication"] and
    .enabled == true and
    .config.mode == "prefetch" and .config.eagerness == "conservative" and
    (.stored != .default)
  ' >/dev/null || fail "Speculative Loading boundary target did not consume the applied setting: $out"
  pass "Speculative Loading exact boundary drives core's speculation configuration from a setting distinct from the plugin's own defaults"
}

VMATRIX_PLUGIN_SLUG=speculation-rules

version_matrix_reset_after_delete() {
  local cli="$1"
  "$cli" db query "
    DELETE FROM wp_options
      WHERE option_name IN ('plsr_speculation_rules', 'plsr_speculation_rules_target_probe');
  " >/dev/null
}

version_matrix_workflow() {
VMATRIX_CASES=$((VMATRIX_CASES + 1))
PLSR_VERSION=1.7.0
say "boundary: speculation-rules $PLSR_VERSION (only admitted patch)"

reset_env wp1
reset_env wp2
reset_case_repositories

say "fetch + verify speculation-rules $PLSR_VERSION (digest-checked artifact only)"
PLSR_ARTIFACT_1=$(fetch_artifact speculation-rules "$PLSR_VERSION" cli1)
PLSR_ARTIFACT_2=$(fetch_artifact speculation-rules "$PLSR_VERSION" cli2)
wp1 plugin install "$PLSR_ARTIFACT_1" --activate >/dev/null
PLSR_INSTALLED_1=$(wp1 plugin get speculation-rules --field=version)
[ "$PLSR_INSTALLED_1" = "$PLSR_VERSION" ] \
  || fail "side 1 installed version mismatch: expected $PLSR_VERSION, got $PLSR_INSTALLED_1"
pass "side 1: speculation-rules $PLSR_VERSION installed from verified artifact, active"

cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "speculation-rules"],
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
"${GIT1[@]}" commit -qm "policy: Speculative Loading $PLSR_VERSION exact-boundary certification"
"${GIT1[@]}" push -qu origin main

seed_speculation_rules_content
wp1 wprism capture --repo=/siterepo
wp1 wprism lint --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: Speculative Loading $PLSR_VERSION setting"
"${GIT1[@]}" push -q origin main

clone_case_target
wp2 plugin install "$PLSR_ARTIFACT_2" >/dev/null
INSTALLED_2=$(wp2 plugin get speculation-rules --field=version)
require_fixture_values INSTALLED_2
[ "$INSTALLED_2" = "$PLSR_VERSION" ] \
  || fail "side 2 installed version mismatch: expected $PLSR_VERSION, got $INSTALLED_2"
wp2 wprism deploy --repo=/siterepo
REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
assert_version_matrix_apply_ready
grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
  || fail "apply canary not clean at speculation-rules $PLSR_VERSION"
check_speculation_rules_boundary_content

wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-final
PLSR_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
rm -rf "siterepo/${PAIR}2/.tmp-final"
[ -z "$PLSR_DIFF" ] \
  || fail "byte-identity broken at speculation-rules $PLSR_VERSION: $PLSR_DIFF"
pass "Speculative Loading $PLSR_VERSION deploys, drives core speculation natively, and recaptures byte-identically"

say "negative control: speculation-rules 1.6.0 (adjacent official release below the exact 1.7.0 contract) must be REFUSED"
# 1.6.0 carries a BYTE-IDENTICAL option surface and identical defaults to
# 1.7.0 (both declare mode/eagerness/authentication with the same enums and
# the same plsr_get_setting_default()). The refusal therefore has to come from
# the declared version_range alone, with no schema difference to lean on.
reset_env wp1
reset_case_repositories

PLSR_IN_RANGE=$(fetch_artifact speculation-rules 1.7.0 cli1)
wp1 plugin install "$PLSR_IN_RANGE" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get speculation-rules --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = "1.7.0" ] \
  || fail "negative control premise did not install exact speculation-rules 1.7.0 bytes"
cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "speculation-rules"],
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
"${GIT1[@]}" commit -qm "policy: Speculative Loading negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_speculation_rules_content
wp1 wprism capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid Speculative Loading state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate speculation-rules >/dev/null
wp1 plugin delete speculation-rules >/dev/null
PLSR_OUT_OF_RANGE=$(fetch_artifact speculation-rules 1.6.0 cli1)
wp1 plugin install "$PLSR_OUT_OF_RANGE" >/dev/null
INSTALLED_OOR=$(wp1 plugin get speculation-rules --field=version)
[ "$INSTALLED_OOR" = "1.6.0" ] \
  || fail "negative control: expected speculation-rules 1.6.0 installed, got $INSTALLED_OOR"
PLSR_REFUSAL_BEFORE=$(wp1 db query \
  "SELECT IFNULL(SHA2(option_value, 256), 'absent') FROM wp_options WHERE option_name = 'plsr_speculation_rules';" \
  --skip-column-names)
require_observed_nonempty "Speculative Loading refusal state baseline" "$PLSR_REFUSAL_BEFORE"
set +e
DEPLOY_OUT=$(wp1 wprism deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] \
  || fail "expected deploy to refuse speculation-rules 1.6.0, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "speculation-rules 1.6.0 refused for the wrong reason (got: $DEPLOY_OUT)"
grep -q "speculation-rules/load.php" <<<"$DEPLOY_OUT" \
  || fail "Speculative Loading refusal did not name the exact basename (got: $DEPLOY_OUT)"
grep -q "1.6.0" <<<"$DEPLOY_OUT" \
  || fail "Speculative Loading refusal did not name installed version 1.6.0 (got: $DEPLOY_OUT)"
if wp1 plugin is-active speculation-rules >/dev/null 2>&1; then
  fail "outside-range speculation-rules 1.6.0 was activated before refusal"
fi
PLSR_REFUSAL_AFTER=$(wp1 db query \
  "SELECT IFNULL(SHA2(option_value, 256), 'absent') FROM wp_options WHERE option_name = 'plsr_speculation_rules';" \
  --skip-column-names)
[ "$PLSR_REFUSAL_AFTER" = "$PLSR_REFUSAL_BEFORE" ] \
  || fail "Speculative Loading outside-range refusal mutated the authored setting"
printf '%s\n' "$DEPLOY_OUT"
pass "official speculation-rules 1.6.0 is loudly refused on the declared range alone despite a byte-identical option surface, remains inactive, and cannot mutate admitted settings"
}
