#!/usr/bin/env bash
# Regression — DUO-3423: every conformance post-condition that reads live
# target state must establish that the observation answered before it can
# accuse Duo.  A load-starved `docker compose run` can return exit 0 with an
# empty stream; comparing or hashing that stream is not evidence of an engine
# regression.  The inventory below is deliberately explicit: it is the
# review record for the family-wide pass, and each entry pins the premise
# helper at the read site.
#
# This is an offline source contract.  It does not run Docker or a pair.
set -euo pipefail
cd "$(dirname "$0")/.." # -> sandbox/

pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

guard() {
  local file="$1" needle="$2"
  [ -f "$file" ] || fail "observation inventory names missing file: $file"
  grep -Fq "$needle" "$file" \
    || fail "$file is missing the target-observation premise: $needle"
}

# Non-empty observation streams.  These are all read from a live target and
# then parsed/compared in an accusation below them.
OBSERVATIONS=(
  'conformance/checks/acf.sh|require_observed_nonempty "conf2 ACF runtime observation"'
  'conformance/checks/contact-form-7.sh|require_observed_nonempty "conf2 Contact Form 7 rendered response"'
  'conformance/checks/contact-form-7.sh|require_observed_nonempty "conf2 legacy Contact Form 7 rendered response"'
  'conformance/checks/contact-form-7.sh|require_observed_nonempty "conf1 post-count baseline before anonymous Contact Form 7 submission"'
  'conformance/checks/contact-form-7.sh|require_observed_nonempty "conf1 post-count after anonymous Contact Form 7 submission"'
  'conformance/checks/elementor.sh|require_observed_nonempty "conf2 Elementor rendered response"'
  'conformance/checks/elementor.sh|require_observed_nonempty "conf2 Elementor regenerated CSS"'
  'conformance/checks/fse.sh|require_duo_answered "conf2 duo plan after active-theme mismatch" json'
  'conformance/checks/fse.sh|require_duo_answered "conf2 duo plan after restoring active theme" json'
  'conformance/checks/ninja-forms.sh|require_observed_nonempty "conf2 Ninja Forms rendered response"'
  'conformance/checks/ninja-forms.sh|require_observed_nonempty "conf2 Ninja Forms API observation"'
  'conformance/checks/ninja-forms.sh|require_observed_nonempty "conf2 identity-map count after verified import"'
  'conformance/checks/ninja-forms.sh|require_observed_nonempty "conf2 identity-state count after verified import"'
  'conformance/checks/ninja-forms.sh|require_observed_nonempty "conf2 applied revision after verified import"'
  'conformance/checks/ninja-forms.sh|require_duo_answered "conf2 duo plan after verified identity restore" json'
  'conformance/checks/ninja-forms.sh|require_observed_nonempty "conf2 identity-map count after stale sidecar rejection"'
  'conformance/checks/ninja-forms.sh|require_observed_nonempty "conf2 live identity mapping after conflict rejection"'
  'conformance/checks/paid-memberships-pro.sh|require_observed_nonempty "conf2 PMPro runtime observation"'
  'conformance/checks/polylang.sh|require_observed_nonempty "conf2 Polylang post translation map"'
  'conformance/checks/polylang.sh|require_observed_nonempty "conf2 Polylang English term translation map"'
  'conformance/checks/polylang.sh|require_observed_nonempty "conf2 Polylang French term translation map"'
  'conformance/checks/polylang.sh|require_observed_nonempty "conf2 Polylang English term language"'
  'conformance/checks/polylang.sh|require_observed_nonempty "conf2 Polylang French term language"'
  'conformance/checks/polylang.sh|require_observed_nonempty "conf2 Polylang post translation term-taxonomy id"'
  'conformance/checks/polylang.sh|require_observed_nonempty "conf2 Polylang term translation term-taxonomy id"'
  'conformance/checks/polylang.sh|require_observed_nonempty "conf2 Polylang post translation serialized description"'
  'conformance/checks/polylang.sh|require_observed_nonempty "conf2 Polylang term translation serialized description"'
  'conformance/checks/polylang.sh|require_observed_nonempty "conf2 Polylang rendered response"'
  'conformance/checks/woocommerce.sh|require_observed_nonempty "conf2 WooCommerce attribute/variation observation"'
  'conformance/checks/woocommerce.sh|require_observed_nonempty "conf2 WooCommerce product-category thumbnail observation"'
  'conformance/checks/woocommerce.sh|require_observed_nonempty "conf2 WooCommerce shipping/tax observation"'
  'conformance/checks/woocommerce.sh|require_observed_nonempty "conf2 WooCommerce merchant-settings observation"'
  'conformance/checks/woocommerce.sh|require_observed_nonempty "conf2 WooCommerce HPOS observation"'
  'conformance/checks/woocommerce.sh|require_observed_nonempty "conf2 WooCommerce runtime-surfaces observation"'
  'conformance/checks/woocommerce.sh|require_observed_nonempty "conf2 WooCommerce projection observation"'
  'conformance/checks/woocommerce.sh|require_observed_nonempty "conf2 WooCommerce Store API filter response"'
  'conformance/checks/woocommerce.sh|require_observed_nonempty "conf2 WooCommerce rendered product response"'
  'conformance/checks/yoast.sh|require_observed_nonempty "conf2 Yoast runtime observation"'
  'conformance/checks/yoast.sh|require_observed_nonempty "conf2 Yoast rendered response"'
  'conformance/postdeploy/ninja-forms.sh|require_observed_nonempty "conf2 Ninja Forms activation cleanup observation"'
  'conformance/seeds/acf.sh|require_observed_nonempty "conf1 ACF seed output"'
  'conformance/seeds/contact-form-7.sh|require_observed_nonempty "conf1 Contact Form 7 seed output"'
  'conformance/seeds/ninja-forms.sh|require_observed_nonempty "conf1 Ninja Forms activation cleanup observation"'
  'conformance/seeds/paid-memberships-pro.sh|require_observed_nonempty "conf1 PMPro seed output"'
  'conformance/seeds/polylang.sh|require_observed_nonempty "conf1 Polylang translated-term seed output"'
  'conformance/seeds/polylang.sh|require_observed_nonempty "conf1 Polylang English category readback"'
  'conformance/seeds/polylang.sh|require_observed_nonempty "conf1 Polylang French category readback"'
  'conformance/seeds/woocommerce.sh|require_observed_nonempty "conf1 WooCommerce tax-class id"'
  'conformance/seeds/yoast.sh|require_observed_nonempty "conf1 Yoast seed rendered response"'
  'conformance/checks/core.sh|require_observed_nonempty "conf2 custom_logo post type"'
  'conformance/checks/core.sh|require_observed_nonempty "conf2 custom_css post type"'
  'conformance/checks/core.sh|require_observed_nonempty "conf2 custom_css post content"'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo pending after apply" json'
  'conformance/checks/core.sh|require_duo_answered "conf1 duo pending unknown-widget probe" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo apply --force-theirs conflict override" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan unforced conflict" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan unforced conflict human view" human'
  'conformance/checks/core.sh|require_duo_answered "conf1 duo capture page deletion" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan referential page deletion" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo apply forced page deletion" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan page deletion retry" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan guard-blocked deletion conflict" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan guard-blocked deletion human view" human'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan local deletion conflict" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan local deletion conflict human view" human'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo apply forced local deletion conflict" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan branch deletion conflict" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan missing guard table" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo apply forced local deletion conflict" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan fresh target deletion interpretation" json'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target nf3_fields deletion count"'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target nf3_field_meta cascade count"'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target nf3_actions deletion count"'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target nf3_action_meta cascade count"'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target retained nf3_forms parent count"'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target nf3_forms parent count after refused deletion"'
  'tests/certify_merge.sh|require_observed_nonempty "A About post title after merge"'
  'tests/certify_merge.sh|require_observed_nonempty "A Hello post title after merge"'
  'tests/certify_merge.sh|require_observed_nonempty "B About post title after apply"'
  'tests/certify_merge.sh|require_observed_nonempty "B Team post title after apply"'
  'tests/certify_merge.sh|require_observed_nonempty "A Team post title after recapture"'
  'tests/certify_merge.sh|require_observed_nonempty "A WooCommerce attribute label after conflict resolution"'
  'tests/certify_merge.sh|require_observed_nonempty "B WooCommerce attribute label after conflict resolution"'
  'tests/certify_merge.sh|require_duo_answered "env B drift plan" json'
  'tests/certify_merge.sh|require_duo_answered "env B apply with preserved local drift" human'
  'tests/certify_merge.sh|require_duo_answered "env B retry plan after preserved drift" json'
  'tests/certify_merge.sh|require_duo_answered "env A unresolved-conflict plan" json'
  'tests/certify_merge.sh|require_duo_answered "env A final lint" json'
  'tests/certify_merge.sh|require_duo_answered "env B final lint" json'
  'tests/certify_adversarial_matrix.sh|require_observed_nonempty "A ledger local id after refused duplicate plan"'
  'tests/certify_adversarial_matrix.sh|require_observed_nonempty "B ledger local id after refused duplicate plan"'
  'tests/certify_adversarial_matrix.sh|require_fixture_ids LOC_LOCAL'
  'tests/certify_adversarial_matrix.sh|require_observed_nonempty "restored ledger local id after identity import"'
  'tests/certify_version_matrix.sh|require_observed_nonempty "side 2 Ninja Forms careers page"'
  'tests/certify_version_matrix.sh|require_observed_nonempty "side 2 Ninja Forms model API"'
  'tests/certify_version_matrix.sh|require_observed_nonempty "CF7 $CF7_VERSION target legacy page"'
  'tests/certify_version_matrix.sh|require_fixture_ids form_id'
  'tests/certify_version_matrix.sh|require_fixture_ids TARGET_FORM_ID TARGET_LEGACY_ID'
  'tests/certify_version_matrix.sh|require_fixture_values TARGET_OLD_ID'
  'tests/certify_version_matrix.sh|require_fixture_values INSTALLED_2'
  'tests/certify_version_matrix.sh|require_fixture_values NEGATIVE_INSTALLED'
  'tests/certify_version_skew_merge.sh|require_observed_nonempty "env1 baseline settings"'
  'tests/certify_version_skew_merge.sh|require_observed_nonempty "env2 baseline settings"'
  'tests/certify_version_skew_merge.sh|require_observed_nonempty "env1 live v2 plugin version"'
  'tests/certify_version_skew_merge.sh|require_observed_nonempty "env1 settings before explicit migration"'
  'tests/certify_version_skew_merge.sh|require_duo_answered "env1 v2 migration" json'
  'tests/certify_version_skew_merge.sh|require_duo_answered "env1 v2 deploy" json'
  'tests/certify_version_skew_merge.sh|require_duo_answered "env1 v2 apply" json'
  'tests/certify_version_skew_merge.sh|require_observed_nonempty "env2 live v2 plugin version"'
  'tests/certify_version_skew_merge.sh|require_duo_answered "env2 v2 migration" json'
  'tests/certify_version_skew_merge.sh|require_duo_answered "env2 v2 deploy" json'
  'tests/certify_version_skew_merge.sh|require_duo_answered "env2 v2 apply" json'
  'tests/certify_version_skew_merge.sh|require_observed_nonempty "env2 settings after v2 apply"'
  'tests/certify_version_skew_merge.sh|require_observed_nonempty "env1 settings after v2 apply"'
  'tests/certify_version_skew_merge.sh|require_duo_answered "env1 final lint" json'
  'tests/certify_version_skew_merge.sh|require_duo_answered "env2 final lint" json'
  'tests/certify_merge.sh|require_duo_answered "B retry apply after capture" json'
  'tests/certify_merge.sh|require_duo_answered "B clean plan after retry" json'
  'tests/certify_ssh_adoption_roundtrip.sh|require_observed_nonempty "target runtime checksum before apply"'
  'tests/certify_ssh_adoption_roundtrip.sh|require_observed_nonempty "target runtime checksum after apply"'
  'tests/certify_ssh_adoption_roundtrip.sh|require_observed_nonempty "target authored banner after apply"'
  'tests/certify_ssh_rollback.sh|require_observed_nonempty "target SSH hostname"'
  'tests/certify_ssh_rollback.sh|require_observed_nonempty "target database identity"'
  'tests/certify_ssh_rollback.sh|require_observed_nonempty "target rollback status"'
  'tests/certify_ssh_rollback.sh|require_observed_nonempty "target plaintext checkpoint count"'
  'tests/certify_ssh_rollback.sh|require_observed_nonempty "target maintenance exclusion state"'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target WooCommerce order-product lookup count"'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target expected repository revision for deletion tombstone"'
  'tests/certify_deletion_matrix.sh|require_duo_answered "target WooCommerce deletion refusal plan" json'
  'tests/certify_deletion_matrix.sh|require_duo_answered "target WooCommerce deletion refusal apply" human'
  'tests/certify_deletion_matrix.sh|require_duo_answered "target WooCommerce forced deletion refusal apply" human'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target retained WooCommerce product id"'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target retained WooCommerce order lookup count"'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target retained WooCommerce order status"'
  'tests/certify_deletion_matrix.sh|require_duo_answered "target WooCommerce deletion retry plan" json'
  'tests/certify_deletion_matrix.sh|require_duo_answered "source child deletion capture" json'
  'tests/certify_deletion_matrix.sh|require_duo_answered "target child deletion apply" json'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target expected repository revision for parent refusal"'
  'tests/certify_deletion_matrix.sh|require_duo_answered "target parent deletion refusal plan" json'
  'tests/certify_deletion_matrix.sh|require_duo_answered "target parent deletion refusal apply" human'
  'tests/certify_deletion_matrix.sh|require_duo_answered "target forced parent deletion refusal apply" human'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target nf3_fields deletion count"'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target nf3_field_meta cascade count"'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target nf3_actions deletion count"'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target nf3_action_meta cascade count"'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target retained nf3_forms parent count"'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target nf3_forms parent count after refused deletion"'
  'tests/certify_deletion_matrix.sh|require_duo_answered "target parent restoration plan" json'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target initial PMPro restriction count"'
  'tests/certify_deletion_matrix.sh|require_duo_answered "target PMPro deletion plan" json'
  'tests/certify_deletion_matrix.sh|require_duo_answered "target PMPro deletion apply" json'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target PMPro restriction count after delete"'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target retained PMPro membership level id"'
  'tests/certify_deletion_matrix.sh|require_observed_nonempty "target retained PMPro page id"'
  'tests/certify_deletion_matrix.sh|require_duo_answered "target PMPro deletion retry plan" json'
)

for item in "${OBSERVATIONS[@]}"; do
  file="${item%%|*}"
  needle="${item#*|}"
  guard "$file" "$needle"
done
pass "all ${#OBSERVATIONS[@]} target-reading postconditions have an answered/premise guard"

# Fixture reads are a different premise: a missing id/value means the hook's
# own fixture did not land.  Keep these separate from observation guards so a
# genuinely expected empty post-deletion read is not misclassified.
FIXTURES=(
  'conformance/checks/contact-form-7.sh|require_fixture_ids CONF1_WPCF7_ID'
  'conformance/checks/contact-form-7.sh|require_fixture_values LEGACY_CONF2_OLD_ID'
  'conformance/checks/elementor.sh|require_fixture_ids PAGE_ID'
  'conformance/checks/ninja-forms.sh|require_fixture_ids CONF2_FORM_ID'
  'conformance/checks/ninja-forms.sh|require_fixture_ids CONF1_FORM_ID PAGE_ID'
  'conformance/checks/polylang.sh|require_fixture_ids POST_EN_ID POST_FR_ID NEWS_ID ACT_ID'
  'conformance/checks/polylang.sh|require_fixture_ids CONF1_POST_EN_ID'
  'conformance/checks/core.sh|require_fixture_values HOME_FILE'
  'conformance/checks/core.sh|require_fixture_values HELLO_FILE'
  'conformance/checks/core.sh|require_fixture_values ATT_FILE'
  'conformance/checks/core.sh|require_fixture_values CHILD_FILE'
  'conformance/checks/core.sh|require_fixture_ids ATT1'
  'conformance/checks/core.sh|require_fixture_ids CHILD1'
  'conformance/postdeploy/contact-form-7.sh|require_fixture_ids FILLER_ID'
  'conformance/postdeploy/core.sh|require_fixture_ids B'
  'conformance/postdeploy/core.sh|require_fixture_ids A'
  'conformance/postdeploy/core.sh|require_fixture_ids B_CHILD A_CHILD'
  'conformance/postdeploy/core.sh|require_fixture_ids DUP'
  'conformance/postdeploy/woocommerce.sh|require_fixture_ids TARGET_ORDER_ID'
  'tests/certify_merge.sh|require_fixture_ids ABOUT_ID_B TEAM_ID_B HELLO_ID_B SIZE_ATTR_ID_B'
  'conformance/seeds/contact-form-7.sh|require_fixture_ids PAGE_ID'
  'conformance/seeds/contact-form-7.sh|require_fixture_ids LEGACY_PAGE_ID'
  'conformance/seeds/ninja-forms.sh|require_fixture_ids NF_FORM_ID'
  'conformance/seeds/ninja-forms.sh|require_fixture_ids PAGE_ID'
  'conformance/seeds/polylang.sh|require_fixture_ids NEWS_TERM_ID ACT_TERM_ID'
  'conformance/seeds/polylang.sh|require_fixture_ids POST_EN POST_FR'
  'tests/certify_deletion_matrix.sh|require_fixture_ids PRODUCT_B'
  'tests/certify_deletion_matrix.sh|require_fixture_ids ORDER_B'
  'tests/certify_deletion_matrix.sh|require_fixture_ids FORM_B FIELD_B ACTION_B'
  'tests/certify_deletion_matrix.sh|require_fixture_ids LEVEL_B PAGE_B'
)
for item in "${FIXTURES[@]}"; do
  guard "${item%%|*}" "${item#*|}"
done
pass "all ${#FIXTURES[@]} target fixture reads have explicit fixture premises"

# Explicit inventory of intentionally empty observations.  These are not
# engine accusations when empty: they assert absence/cleanliness, and their
# positive failure branch already pastes the before/after evidence where a
# mutation could be reported.  Do not turn these into non-empty guards: an
# empty result is the successful state they are proving.
grep -Fq '[ -z "$(wp_conf2 post list --post_type=page --name=home --field=ID)" ] || fail "Home page survived exact deletion"' conformance/checks/core.sh \
  || fail "the expected-empty Home deletion predicate disappeared from the explicit exemption inventory"
grep -Fq 'git -C "$CONF_REPO2" status --porcelain --untracked-files=all' conformance/checks/core.sh \
  || fail "the clean-repository observation exemption lost its direct git status evidence"
grep -Fq '[ -z "$(wp_conf2 post list --post_type=post --name=hello-conformance --field=ID)" ]' conformance/checks/core.sh \
  || fail "the expected-empty Hello deletion predicate disappeared from the explicit exemption inventory"
grep -Fq '[ -z "$(wp_conf2 post list --post_type=attachment --name=conformance-logo --field=ID)" ]' conformance/checks/core.sh \
  || fail "the expected-empty attachment deletion predicate disappeared from the explicit exemption inventory"
grep -Fq 'rollback-alpha --field=ID)' conformance/checks/core.sh \
  || fail "the expected-empty rollback deletion predicates disappeared from the explicit exemption inventory"
grep -Fq 'SELECT uuid FROM wp_duo_map WHERE uuid' tests/certify_adversarial_matrix.sh \
  || fail "the expected-empty identity-map setup predicate disappeared from the explicit exemption inventory"
grep -Fq 'git -C siterepo/certmatrix1 status --porcelain -- state' tests/certify_adversarial_matrix.sh \
  || fail "the adversarial clean-repository observation exemption lost its direct git status evidence"
grep -Fq 'environment_collection_failed' tests/certify_reference_bundle.sh \
  || fail "the reference-bundle environment collection empty-output exemption disappeared"
version_matrix="tests/certify_version_matrix.sh"
[ "$(grep -Fc 'require_fixture_values INSTALLED_2' "$version_matrix")" -eq 7 ] \
  || fail "version-matrix target plugin-version premises must cover all seven certified plugin loops"
[ "$(grep -Fc 'require_fixture_values NEGATIVE_INSTALLED' "$version_matrix")" -eq 4 ] \
  || fail "version-matrix negative-control plugin-version premises must cover all four below-range controls"
pass "expected-empty absence/clean-repository predicates remain explicitly inventoried rather than falsely premise-guarded"

echo "REGRESS_TARGET_OBSERVATION_PREMISES PASSED"
