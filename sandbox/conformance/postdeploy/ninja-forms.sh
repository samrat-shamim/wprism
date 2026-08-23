#!/usr/bin/env bash
# Ninja Forms manifest post-deploy hook (task DUO-3223 — closes the gap
# task #19 found when the conf-pair harness moved conf2's plugin activation
# out of install_env and into `wp duo deploy`): removes conf2's OWN
# activation-created "Contact Me" sample form, the exact same cleanup
# seeds/ninja-forms.sh already does for conf1, just delayed until conf2
# actually HAS one to remove.
#
# Ninja Forms mints a "Contact Me" sample form on every activation
# (confirmed live in grind round R1-A). nf3_forms is a
# "mapped"-identity table (Snapshot.php's identity-modes docblock names
# nf3_forms/nf3_fields/nf3_actions specifically): a fresh row always mints a
# random uuid, with no natural key for apply to converge on — so two
# independently-activation-created "Contact Me" rows can NEVER be
# recognized as "the same" form, on principle, not as a bug to fix. Left
# alone, capturing conf1's row and applying it to conf2 creates a SECOND,
# distinct "Contact Me" row alongside conf2's own activation-created one, a
# genuine round-trip mismatch (extra untracked rows in nf3_forms/nf3_fields/
# nf3_actions) that is not a capture/apply bug — it's this exact activation
# side effect, uncleaned.
#
# Why this can't be seeds/ninja-forms.sh's job for conf2 too: at seed time
# conf2 has no active Ninja Forms install at all (install_env's role=target
# is plugin-files-only — this is the harness's real promotion path, `wp duo
# deploy`, exercising actual activate_plugin() reconciliation instead of
# pre-activating both sides upfront). conf2's own "Contact Me" row doesn't
# exist to remove until deploy activates the plugin, which is why this
# cleanup runs here — run.sh's generic post-deploy hook point
# (conformance/postdeploy/<name>.sh, see run.sh's header comment for the
# full timing contract), invoked strictly after `wp duo deploy` and
# strictly before `duo apply` on conf2 only. Invoked by conformance/run.sh
# with wp_conf2/$COMPOSE already exported; runs from the sandbox/ directory.
set -euo pipefail

# Deliberately identical to seeds/ninja-forms.sh's REMOVE_CONTACT_ME_PHP —
# same row shape, same cascade, just conf2 instead of conf1 and a later
# point in the flow. Not factored into a shared helper: every seed/check/
# postdeploy script in this harness is self-contained by convention (no
# shared bash library exists to source from), so duplication with an
# explicit cross-reference (this comment, and the mirror one in the seed)
# is the established pattern here, not an oversight.
read -r -d '' REMOVE_CONTACT_ME_PHP <<'PHPEOF' || true
<?php
global $wpdb;
$id = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}nf3_forms WHERE title = 'Contact Me'");
if ($id) {
    $fieldIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nf3_fields WHERE parent_id = %d", $id));
    $actionIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nf3_actions WHERE parent_id = %d", $id));
    foreach ($fieldIds as $fid) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_field_meta WHERE parent_id = %d", $fid));
    }
    foreach ($actionIds as $aid) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_action_meta WHERE parent_id = %d", $aid));
    }
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_fields WHERE parent_id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_actions WHERE parent_id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_form_meta WHERE parent_id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_upgrades WHERE id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_forms WHERE id = %d", $id));
    echo "removed this environment's own activation-created 'Contact Me' form (id=$id)\n";
}
// DUO-3381: report the POST-CONDITION, not just what was attempted. This
// cleanup is the premise for run.sh's cross-environment byte-diff — a row
// left behind here surfaces there as "round-trip mismatch between conf1
// and conf2", an accusation against capture/apply for a fixture this
// cleanup failed to establish.
echo "contact_me_remaining=" . (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}nf3_forms WHERE title = 'Contact Me'") . "\n";
PHPEOF
printf '%s' "$REMOVE_CONTACT_ME_PHP" > "${CONF_REPO2:-siterepo/conf2}"/.tmp-nf-remove-contact-me.php
NF_CLEANUP=$(wp_conf2 eval-file /siterepo/.tmp-nf-remove-contact-me.php)
rm -f "${CONF_REPO2:-siterepo/conf2}"/.tmp-nf-remove-contact-me.php
printf '%s\n' "$NF_CLEANUP"
require_observed_nonempty "conf2 Ninja Forms activation cleanup observation" "$NF_CLEANUP"
require_fixture_state "conf2's own activation-created 'Contact Me' form is gone from nf3_forms" \
  "contact_me_remaining=0" "$(grep -o 'contact_me_remaining=[0-9]*' <<<"$NF_CLEANUP" | tail -1)"

# Force every mapped Ninja Forms identity family far away from the source,
# preserve target-only submission/runtime state, and seed both generations of
# stale cache. The first real apply must rebind block/table references to these
# target-local ids while provider v2 removes both orphan cache stores and does
# not cross the runtime boundary.
cat > "${CONF_REPO2:-siterepo/conf2}"/.tmp-nf-hostile-target.php <<'PHPEOF'
<?php
global $wpdb;
foreach ([
    'nf3_forms' => 700000,
    'nf3_form_meta' => 710000,
    'nf3_fields' => 720000,
    'nf3_field_meta' => 730000,
    'nf3_actions' => 740000,
    'nf3_action_meta' => 750000,
] as $suffix => $next) {
    $table = $wpdb->prefix . $suffix;
    if ($wpdb->query("ALTER TABLE `$table` AUTO_INCREMENT = $next") === false) {
        throw new RuntimeException("Ninja Forms target identity divergence failed for $suffix");
    }
}

$submission = wp_insert_post([
    'post_type' => 'nf_sub',
    'post_status' => 'publish',
    'post_title' => 'Target Runtime Submission',
], true);
if (is_wp_error($submission) || (int) $submission <= 0) {
    throw new RuntimeException('Ninja Forms target runtime submission fixture failed');
}
update_post_meta((int) $submission, '_form_id', '424242');
update_post_meta((int) $submission, '_field_999', 'target-submission-survives');
update_option('ninja_forms_target_undeclared_neighbor', 'target-neighbor-survives', false);

$orphan = serialize([
    'id' => 777777,
    'fields' => [['settings' => ['label' => 'stale target field'], 'id' => 888888]],
    'actions' => [],
    'settings' => ['title' => 'stale target cache'],
]);
if ($wpdb->insert($wpdb->prefix . 'nf3_upgrades', [
    'id' => 777777,
    'cache' => $orphan,
    'stage' => 1,
    'maintenance' => 0,
], ['%d', '%s', '%d', '%d']) === false) {
    throw new RuntimeException('Ninja Forms orphan table cache fixture failed');
}
update_option('nf_form_777777', $orphan, false);

echo 'submission_id=' . (int) $submission . "\n";
echo 'orphan_table_cache=' . (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}nf3_upgrades WHERE id=777777"
) . "\n";
echo 'orphan_maintenance=' . (int) $wpdb->get_var(
    "SELECT maintenance+0 FROM {$wpdb->prefix}nf3_upgrades WHERE id=777777"
) . "\n";
echo 'orphan_legacy_cache=' . (get_option('nf_form_777777', null) === null ? 0 : 1) . "\n";
PHPEOF
NF_HOSTILE=$($COMPOSE run --rm -T cli2 wp eval-file /siterepo/.tmp-nf-hostile-target.php)
rm -f "${CONF_REPO2:-siterepo/conf2}"/.tmp-nf-hostile-target.php
printf '%s\n' "$NF_HOSTILE"
require_observed_nonempty "conf2 Ninja Forms hostile-target observation" "$NF_HOSTILE"
NF_SUBMISSION_ID=$(grep -o 'submission_id=[0-9]*' <<<"$NF_HOSTILE" | grep -o '[0-9]*')
require_fixture_ids NF_SUBMISSION_ID
require_fixture_state "conf2 orphan Ninja Forms table cache exists before apply" \
  "orphan_table_cache=1" "$(grep -o 'orphan_table_cache=[0-9]*' <<<"$NF_HOSTILE" | tail -1)"
require_fixture_state "conf2 orphan Ninja Forms cache is not in plugin maintenance mode" \
  "orphan_maintenance=0" "$(grep -o 'orphan_maintenance=[0-9]*' <<<"$NF_HOSTILE" | tail -1)"
require_fixture_state "conf2 orphan Ninja Forms legacy cache exists before apply" \
  "orphan_legacy_cache=1" "$(grep -o 'orphan_legacy_cache=[0-9]*' <<<"$NF_HOSTILE" | tail -1)"
