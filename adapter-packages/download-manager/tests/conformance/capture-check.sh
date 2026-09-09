#!/usr/bin/env bash
# Source-side proof for the three operations the disposition actually claims:
# capture, compile, recapture. It asserts what the capsule declared reaches the
# canonical tree and, just as importantly, that the withheld families do not.
set -euo pipefail

REPO="${CONF_REPO1:-siterepo/conf1}"

read -r -d '' OBSERVE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
$package = get_page_by_path('wprism-dlm-handbook', OBJECT, 'wpdmpro');
$category = get_term_by('slug', 'wprism-dlm-handbooks', 'wpdmcategory');
if (!$package || !$category) {
    throw new RuntimeException('Download Manager source fixtures are missing');
}
echo wp_json_encode([
    'category' => (int) $category->term_id,
    'masterkey_present' => get_post_meta($package->ID, '__wpdm_masterkey', true) !== '',
    'package' => (int) $package->ID,
    'style' => get_term_meta($category->term_id, '__wpdm_style', true),
    'version' => get_post_meta($package->ID, '__wpdm_version', true),
]);
PHPEOF

OBSERVE_FILE="$REPO/.tmp-download-manager-observe.php"
printf '%s' "$OBSERVE_PHP" > "$OBSERVE_FILE"
OBSERVE_OUT=$(wp_conf1 eval-file /siterepo/.tmp-download-manager-observe.php)
rm -f "$OBSERVE_FILE"
require_observed_nonempty "Download Manager native capture observation" "$OBSERVE_OUT"
OBSERVED=$(printf '%s\n' "$OBSERVE_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$OBSERVED" | jq -e '.masterkey_present == true and .version == "2.1.0" and .style == "grid"' >/dev/null \
  || fail "the native source no longer holds the seeded package and category state: $OBSERVED"

PACKAGE_FILE=$(find "$REPO/state/posts/wpdmpro" -name '*--wprism-dlm-handbook.md' -type f 2>/dev/null | head -1)
[ -n "$PACKAGE_FILE" ] || fail "capture published no canonical wpdmpro document"

# Match the exact canonical meta KEY, never a bare substring: __wpdm_password
# is withheld while __wpdm_password_lock is authored, and __wpdm_package_size is
# withheld while __wpdm_page_template is authored. A substring test conflates
# them and reports a withheld surface that never appeared (measured: it failed
# this sweep on __wpdm_password_lock's presence alone).
meta_key_present() { # meta_key_present <file> <exact meta key>
  grep -qE "^[[:space:]]*\"$2\":" "$1"
}

# Declared authored intent must be present...
for expected in '__wpdm_version' '__wpdm_link_label' '__wpdm_quota' '__wpdm_changelog' '__wpdm_files' '__wpdm_terms_check_label' '__wpdm_password_lock'; do
  if ! meta_key_present "$PACKAGE_FILE" "$expected"; then
    fail "declared authored package field $expected is absent from the canonical document"
  fi
done

# ...and every withheld family must be absent. A regression that quietly
# reclassified any of these would otherwise pass every other assertion here.
for withheld in '__wpdm_masterkey' '__wpdm_password' '__wpdm_icon' '__wpdm_preview' '__wpdm_terms_page' '__wpdm_download_count' '__wpdm_view_count' '__wpdm_package_size' '__wpdm_package_size_b'; do
  if meta_key_present "$PACKAGE_FILE" "$withheld"; then
    fail "withheld surface $withheld reached the canonical document"
  fi
done

CATEGORY_FILE=$(grep -rl 'wprism-dlm-handbooks' "$REPO/state/terms" 2>/dev/null | head -1)
[ -n "$CATEGORY_FILE" ] || fail "capture published no canonical wpdmcategory document"
if ! meta_key_present "$CATEGORY_FILE" '__wpdm_style'; then
  fail "the declared category presentation meta is absent from the canonical term document"
fi
if meta_key_present "$CATEGORY_FILE" '__wpdm_icon'; then
  fail "the withheld category icon URL reached the canonical term document"
fi

EMBED_FILE=$(find "$REPO/state/posts/page" -name '*--wprism-dlm-embed.md' -type f 2>/dev/null | head -1)
[ -n "$EMBED_FILE" ] || fail "capture published no canonical document for the embedding page"
grep -qE '\[wpdm_package id="\{\{post:[0-9a-f-]{36}\}\}"\]' "$EMBED_FILE" \
  || fail "the [wpdm_package] embed did not tokenize its package reference"
grep -qE '\[wpdm_direct_link id="\{\{post:[0-9a-f-]{36}\}\}"' "$EMBED_FILE" \
  || fail "the [wpdm_direct_link] embed did not tokenize its package reference"

printf '%s\n' "$OBSERVED"
pass "Download Manager capture carries the declared package, category and embed intent and leaves every withheld credential, URL and counter family behind"
