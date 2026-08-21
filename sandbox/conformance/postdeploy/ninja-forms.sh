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
